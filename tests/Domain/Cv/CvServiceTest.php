<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cv;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvAnalysisAlreadyFinished;
use App\Domain\Cv\CvAnalysisFailed;
use App\Domain\Cv\CvAnalysisResponseParserInterface;
use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvService;
use App\Domain\Cv\CvUpload;
use App\Domain\Cv\InvalidCvAnalysisResponse;
use App\Domain\Cv\InvalidCvFile;
use App\Domain\Cv\ParsedCvAnalysis;
use App\Domain\Cv\Seniority;
use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmClientNotFound;
use App\Domain\Llm\LlmModelNotSelected;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Domain\Skill\InvalidSkillName;
use App\Domain\Skill\SkillName;
use App\Entity\CvProfile;
use App\Entity\Skill;
use App\Infrastructure\Llm\EmptyLlmAttachment;
use App\Infrastructure\Llm\JsonSchema;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Llm\LlmResponse;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\InvalidEncryptionKey;
use App\Infrastructure\Storage\CvFileUnreadable;
use App\Infrastructure\Storage\CvStorageInterface;
use App\Repository\CvProfileRepositoryInterface;
use App\Repository\SkillRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CvServiceTest extends TestCase
{
    private const string PDF = '%PDF-1.4 cv';
    private const string RAW = '{"analysis":"raw"}';

    public function testStoresTheFileUnderAGeneratedNameAndSavesAPendingProfile(): void
    {
        $upload = new CvUpload('/tmp/phpUpload', 'My CV.pdf');

        $storedFile = null;
        $storage = $this->createMock(CvStorageInterface::class);
        $storage->expects(self::once())
            ->method('store')
            ->with($upload, self::isInstanceOf(CvFile::class))
            ->willReturnCallback(static function (CvUpload $upload, CvFile $file) use (&$storedFile): void {
                $storedFile = $file;
            });

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('save')->with(self::isInstanceOf(CvProfile::class));

        $cvProfile = self::service(cvProfiles: $cvProfiles, storage: $storage)->upload($upload);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf\z/', $cvProfile->getFile()->storedName);
        self::assertSame('My CV.pdf', $cvProfile->getFile()->originalName);
        self::assertEquals($storedFile, $cvProfile->getFile());
        self::assertSame(LlmOperationStatus::Pending, $cvProfile->getAnalysisStatus());
    }

    public function testEveryUploadGetsItsOwnStoredName(): void
    {
        $service = self::service();
        $upload = new CvUpload('/tmp/phpUpload', 'cv.pdf');

        self::assertNotSame(
            $service->upload($upload)->getFile()->storedName,
            $service->upload($upload)->getFile()->storedName,
        );
    }

    public function testRejectsAnUnusableOriginalNameBeforeStoringAnything(): void
    {
        $storage = $this->createMock(CvStorageInterface::class);
        $storage->expects(self::never())->method('store');

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::never())->method('save');

        $this->expectException(InvalidCvFile::class);

        self::service(cvProfiles: $cvProfiles, storage: $storage)->upload(new CvUpload('/tmp/phpUpload', "\0"));
    }

    public function testAnalysisSendsTheStoredPdfWithTheParserSchemaAndSavesTheResult(): void
    {
        $profile = self::pendingProfile();
        $schema = JsonSchema::fromString('{"type":"object"}');
        $analysis = new CvAnalysis('Анализ', 'Analysis', 12, Seniority::Senior, null);

        $storage = $this->createMock(CvStorageInterface::class);
        $storage->expects(self::once())->method('read')->with($profile->getFile())->willReturn(self::PDF);

        $llm = $this->createMock(LlmServiceInterface::class);
        $llm->expects(self::once())
            ->method('generate')
            ->with(
                LlmTask::CvAnalysis,
                self::callback(static fn (LlmRequest $request): bool => null !== $request->attachment
                    && self::PDF === $request->attachment->contents
                    && $schema === $request->responseSchema
                    && str_contains($request->prompt, 'English')),
            )
            ->willReturn(new LlmResponse(self::RAW));

        $parser = $this->createMock(CvAnalysisResponseParserInterface::class);
        $parser->method('schema')->willReturn($schema);
        $parser->expects(self::once())
            ->method('parse')
            ->with(self::RAW)
            ->willReturn(new ParsedCvAnalysis($analysis, ['PHP']));

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('save')->with($profile);

        self::service($cvProfiles, $storage, $llm, $parser)->analyze($profile);

        self::assertSame(LlmOperationStatus::Done, $profile->getAnalysisStatus());
        self::assertEquals($analysis, $profile->getAnalysis());
        self::assertSame(self::RAW, $profile->getRawLlmResponse());
        self::assertSame(['php'], self::skillNames($profile));
    }

    public function testSkillsAreCanonicalisedDeduplicatedAndExistingOnesReused(): void
    {
        $profile = self::pendingProfile();
        $existingPhp = new Skill(SkillName::fromString('php'));

        $skills = $this->createMock(SkillRepositoryInterface::class);
        $skills->expects(self::once())
            ->method('findByNames')
            ->with([
                SkillName::fromString('php'),
                SkillName::fromString('symfony'),
                SkillName::fromString('ruby on rails'),
            ])
            ->willReturn([$existingPhp]);
        $saved = [];
        $skills->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(static function (Skill $skill) use (&$saved): void {
                $saved[] = $skill->getName();
            });

        self::service(
            llm: self::llmReturning(self::RAW),
            parser: self::parserReturning([' PHP ', 'Symfony', 'php', "Ruby  on\tRails", 'SYMFONY']),
            skills: $skills,
        )->analyze($profile);

        self::assertSame(['symfony', 'ruby on rails'], $saved);
        $profileSkills = $profile->getSkills();
        self::assertCount(3, $profileSkills);
        self::assertSame($existingPhp, $profileSkills[0]);
        self::assertSame(['php', 'symfony', 'ruby on rails'], self::skillNames($profile));
    }

    public function testInvalidResponseMarksTheProfileFailedKeepingTheRawResponse(): void
    {
        $profile = self::pendingProfile();
        $invalid = InvalidCvAnalysisResponse::schemaViolation(['The property skills is required']);

        $parser = self::createStub(CvAnalysisResponseParserInterface::class);
        $parser->method('schema')->willReturn(JsonSchema::fromString('{}'));
        $parser->method('parse')->willThrowException($invalid);

        $skills = $this->createMock(SkillRepositoryInterface::class);
        $skills->expects(self::never())->method('save');

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('save')->with($profile);

        $exception = self::analyzeFailing(
            self::service($cvProfiles, llm: self::llmReturning(self::RAW), parser: $parser, skills: $skills),
            $profile,
        );

        self::assertSame($invalid, $exception->getPrevious());
        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertSame(self::RAW, $profile->getRawLlmResponse());
    }

    public function testUnusableSkillNameMarksTheProfileFailedKeepingTheRawResponse(): void
    {
        $profile = self::pendingProfile();

        $skills = $this->createMock(SkillRepositoryInterface::class);
        $skills->expects(self::never())->method('findByNames');
        $skills->expects(self::never())->method('save');

        $exception = self::analyzeFailing(
            self::service(
                llm: self::llmReturning(self::RAW),
                parser: self::parserReturning(['PHP', "\u{00A0}\t"]),
                skills: $skills,
            ),
            $profile,
        );

        self::assertInstanceOf(InvalidSkillName::class, $exception->getPrevious());
        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertSame(self::RAW, $profile->getRawLlmResponse());
    }

    public function testFailedLlmRequestLeavesTheProfilePendingForARetry(): void
    {
        $profile = self::pendingProfile();
        $failure = LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 503');

        $llm = self::createStub(LlmServiceInterface::class);
        $llm->method('generate')->willThrowException($failure);

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::never())->method('save');

        try {
            self::service($cvProfiles, llm: $llm)->analyze($profile);
            self::fail('Expected the LLM failure to propagate.');
        } catch (LlmRequestFailed $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(LlmOperationStatus::Pending, $profile->getAnalysisStatus());
    }

    #[DataProvider('settingsFailures')]
    public function testMissingLlmSettingsMarkTheProfileFailed(\Throwable $failure): void
    {
        $profile = self::pendingProfile();

        $llm = self::createStub(LlmServiceInterface::class);
        $llm->method('generate')->willThrowException($failure);

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('save')->with($profile);

        $exception = self::analyzeFailing(self::service($cvProfiles, llm: $llm), $profile);

        self::assertSame($failure, $exception->getPrevious());
        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertNull($profile->getRawLlmResponse());
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function settingsFailures(): iterable
    {
        yield 'no model selected' => [LlmModelNotSelected::forTask(LlmTask::CvAnalysis)];
        yield 'no client' => [LlmClientNotFound::forProvider(LlmProvider::Gemini)];
        yield 'no api key' => [ApiKeyNotSet::forProvider(LlmProvider::Gemini)];
        yield 'undecryptable api key' => [ApiKeyDecryptionFailed::create()];
        yield 'no encryption key' => [InvalidEncryptionKey::notSet()];
    }

    /**
     * @param class-string<\Throwable> $expectedCause
     */
    #[DataProvider('fileFailures')]
    public function testUnusableStoredFileMarksTheProfileFailedWithoutCallingTheLlm(
        ?\Throwable $readFailure,
        string $expectedCause,
    ): void {
        $profile = self::pendingProfile();

        $storage = self::createStub(CvStorageInterface::class);
        if (null === $readFailure) {
            $storage->method('read')->willReturn('');
        } else {
            $storage->method('read')->willThrowException($readFailure);
        }

        $llm = $this->createMock(LlmServiceInterface::class);
        $llm->expects(self::never())->method('generate');

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('save')->with($profile);

        $exception = self::analyzeFailing(self::service($cvProfiles, $storage, $llm), $profile);

        self::assertInstanceOf($expectedCause, $exception->getPrevious());
        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertNull($profile->getRawLlmResponse());
    }

    /**
     * @return iterable<string, array{?\Throwable, class-string<\Throwable>}>
     */
    public static function fileFailures(): iterable
    {
        yield 'unreadable' => [
            CvFileUnreadable::create('3f9a1c.pdf', new \RuntimeException('No such file')),
            CvFileUnreadable::class,
        ];
        yield 'empty' => [null, EmptyLlmAttachment::class];
    }

    public function testFinishedProfileIsNotAnalyzedAgain(): void
    {
        $profile = self::pendingProfile();
        $profile->markAnalysisFailed('{"first":true}');

        $storage = $this->createMock(CvStorageInterface::class);
        $storage->expects(self::never())->method('read');

        $llm = $this->createMock(LlmServiceInterface::class);
        $llm->expects(self::never())->method('generate');

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::never())->method('save');

        try {
            self::service($cvProfiles, $storage, $llm)->analyze($profile);
            self::fail('Expected a finished profile to be rejected.');
        } catch (CvAnalysisAlreadyFinished) {
            self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
            self::assertSame('{"first":true}', $profile->getRawLlmResponse());
        }
    }

    public function testGivingUpMarksAPendingAnalysisFailed(): void
    {
        $profile = self::pendingProfile();

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('save')->with($profile);

        self::service($cvProfiles)->giveUpAnalysis($profile);

        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertNull($profile->getRawLlmResponse());
    }

    public function testGivingUpLeavesAFinishedAnalysisAsItIs(): void
    {
        $profile = self::pendingProfile();
        $profile->markAnalysisFailed('{"first":true}');

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::never())->method('save');

        self::service($cvProfiles)->giveUpAnalysis($profile);

        self::assertSame(LlmOperationStatus::Failed, $profile->getAnalysisStatus());
        self::assertSame('{"first":true}', $profile->getRawLlmResponse());
    }

    private static function analyzeFailing(CvService $service, CvProfile $profile): CvAnalysisFailed
    {
        try {
            $service->analyze($profile);
        } catch (CvAnalysisFailed $exception) {
            return $exception;
        }

        self::fail('Expected the analysis to fail.');
    }

    private static function service(
        ?CvProfileRepositoryInterface $cvProfiles = null,
        ?CvStorageInterface $storage = null,
        ?LlmServiceInterface $llm = null,
        ?CvAnalysisResponseParserInterface $parser = null,
        ?SkillRepositoryInterface $skills = null,
    ): CvService {
        if (null === $storage) {
            $storage = self::createStub(CvStorageInterface::class);
            $storage->method('read')->willReturn(self::PDF);
        }

        return new CvService(
            $cvProfiles ?? self::createStub(CvProfileRepositoryInterface::class),
            $storage,
            $llm ?? self::createStub(LlmServiceInterface::class),
            $parser ?? self::parserReturning([]),
            $skills ?? self::createStub(SkillRepositoryInterface::class),
        );
    }

    /**
     * @param non-empty-string $text
     */
    private static function llmReturning(string $text): LlmServiceInterface
    {
        $llm = self::createStub(LlmServiceInterface::class);
        $llm->method('generate')->willReturn(new LlmResponse($text));

        return $llm;
    }

    /**
     * @param list<non-empty-string> $skillNames
     */
    private static function parserReturning(array $skillNames): CvAnalysisResponseParserInterface
    {
        $parser = self::createStub(CvAnalysisResponseParserInterface::class);
        $parser->method('schema')->willReturn(JsonSchema::fromString('{}'));
        $parser->method('parse')->willReturn(
            new ParsedCvAnalysis(new CvAnalysis('Анализ', 'Analysis', null, null, null), $skillNames),
        );

        return $parser;
    }

    /**
     * @return list<string>
     */
    private static function skillNames(CvProfile $profile): array
    {
        return array_map(static fn (Skill $skill): string => $skill->getName(), $profile->getSkills());
    }

    private static function pendingProfile(): CvProfile
    {
        return new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
    }
}
