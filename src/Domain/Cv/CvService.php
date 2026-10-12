<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmClientNotFound;
use App\Domain\Llm\LlmModelNotSelected;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Domain\Skill\InvalidSkillName;
use App\Domain\Skill\SkillName;
use App\Entity\CvProfile;
use App\Entity\Skill;
use App\Infrastructure\Llm\EmptyLlmAttachment;
use App\Infrastructure\Llm\LlmAttachment;
use App\Infrastructure\Llm\LlmAttachmentType;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\InvalidEncryptionKey;
use App\Infrastructure\Storage\CvFileUnreadable;
use App\Infrastructure\Storage\CvStorageInterface;
use App\Repository\CvProfileRepositoryInterface;
use App\Repository\SkillRepositoryInterface;

final readonly class CvService implements CvServiceInterface
{
    private const string ANALYSIS_PROMPT = <<<'PROMPT'
        Analyze the attached CV for a job search. Write the analysis twice with the same content: in the language
        the CV is written in, and in English. Cover the candidate's main experience, strengths and the roles they fit.
        Take the other fields only from what the CV states; use null or an empty list where it says nothing.
        List each skill (technology, programming language, framework, tool) once, under its usual name.
        Under languages list only spoken human languages with a CEFR level, never programming languages.
        PROMPT;

    public function __construct(
        private CvProfileRepositoryInterface $cvProfiles,
        private CvStorageInterface $storage,
        private LlmServiceInterface $llm,
        private CvAnalysisResponseParserInterface $parser,
        private SkillRepositoryInterface $skills,
    ) {
    }

    public function upload(CvUpload $upload): CvProfile
    {
        $file = CvFile::create(bin2hex(random_bytes(16)).'.pdf', $upload->originalName);
        $this->storage->store($upload, $file);

        $cvProfile = new CvProfile($file);
        $this->cvProfiles->save($cvProfile);

        return $cvProfile;
    }

    public function analyze(CvProfile $cvProfile): void
    {
        $cvProfile->assertAnalysisPending();

        try {
            $response = $this->llm->generate(LlmTask::CvAnalysis, new LlmRequest(
                self::ANALYSIS_PROMPT,
                attachment: LlmAttachment::fromContents(
                    LlmAttachmentType::Pdf,
                    $this->storage->read($cvProfile->getFile()),
                ),
                responseSchema: $this->parser->schema(),
            ));
        } catch (
            CvFileUnreadable
            |EmptyLlmAttachment
            |LlmModelNotSelected
            |LlmClientNotFound
            |ApiKeyNotSet
            |ApiKeyDecryptionFailed
            |InvalidEncryptionKey $exception
        ) {
            $this->failAnalysis($cvProfile, null, $exception);
        }

        try {
            $parsed = $this->parser->parse($response->text);
            $skillNames = self::canonicalSkillNames($parsed->skillNames);
        } catch (InvalidCvAnalysisResponse|InvalidSkillName $exception) {
            $this->failAnalysis($cvProfile, $response->text, $exception);
        }

        $cvProfile->markAnalysisDone($parsed->analysis, $this->findOrCreateSkills($skillNames), $response->text);
        $this->cvProfiles->save($cvProfile);
    }

    public function giveUpAnalysis(CvProfile $cvProfile): void
    {
        if (LlmOperationStatus::Pending !== $cvProfile->getAnalysisStatus()) {
            return;
        }

        $cvProfile->markAnalysisFailed();
        $this->cvProfiles->save($cvProfile);
    }

    private function failAnalysis(CvProfile $cvProfile, ?string $rawLlmResponse, \Throwable $cause): never
    {
        $cvProfile->markAnalysisFailed($rawLlmResponse);
        $this->cvProfiles->save($cvProfile);

        throw CvAnalysisFailed::because($cause);
    }

    /**
     * @param list<string> $names
     *
     * @return list<SkillName> without repeats
     */
    private static function canonicalSkillNames(array $names): array
    {
        $unique = [];
        foreach ($names as $name) {
            $skillName = SkillName::fromString($name);
            $unique[$skillName->value] = $skillName;
        }

        return array_values($unique);
    }

    /**
     * @param list<SkillName> $names
     *
     * @return list<Skill>
     */
    private function findOrCreateSkills(array $names): array
    {
        $existing = [];
        foreach ($this->skills->findByNames($names) as $skill) {
            $existing[$skill->getName()] = $skill;
        }

        $skills = [];
        foreach ($names as $name) {
            $skill = $existing[$name->value] ?? null;
            if (null === $skill) {
                $skill = new Skill($name);
                $this->skills->save($skill);
            }

            $skills[] = $skill;
        }

        return $skills;
    }
}
