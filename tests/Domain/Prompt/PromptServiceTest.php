<?php

declare(strict_types=1);

namespace App\Tests\Domain\Prompt;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Domain\Cv\LanguageLevel;
use App\Domain\Cv\LanguageProficiency;
use App\Domain\Cv\SalaryExpectation;
use App\Domain\Cv\Seniority;
use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmClientNotFound;
use App\Domain\Llm\LlmModelNotSelected;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Domain\Platform\PlatformAccessType;
use App\Domain\Prompt\AnalysedCvProfileNotFound;
use App\Domain\Prompt\EmptyPromptText;
use App\Domain\Prompt\PromptGenerationAlreadyFinished;
use App\Domain\Prompt\PromptGenerationFailed;
use App\Domain\Prompt\PromptGenerationInProgress;
use App\Domain\Prompt\PromptService;
use App\Domain\Prompt\PromptSource;
use App\Domain\Prompt\PromptText;
use App\Domain\Skill\SkillName;
use App\Entity\CvProfile;
use App\Entity\Platform;
use App\Entity\Prompt;
use App\Entity\Skill;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Llm\LlmResponse;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\InvalidEncryptionKey;
use App\Repository\CvProfileRepositoryInterface;
use App\Repository\PromptRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromptServiceTest extends TestCase
{
    private const string DRAFT = 'Like senior PHP roles.';

    public function testFirstRequestCreatesAPendingPromptFromTheLatestAnalysedProfile(): void
    {
        $platform = self::platform();
        $cvProfile = self::analysedProfile();

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::once())->method('findByPlatform')->with($platform)->willReturn(null);
        $prompts->expects(self::once())->method('save')->with(self::isInstanceOf(Prompt::class));

        $prompt = self::service($prompts, self::cvProfilesFinding($cvProfile))->requestGeneration($platform);

        self::assertSame($platform, $prompt->getPlatform());
        self::assertSame($cvProfile, $prompt->getCvProfile());
        self::assertSame(LlmOperationStatus::Pending, $prompt->getGenerationStatus());
    }

    public function testRegenerationKeepsTheCurrentTextAndTakesTheLatestAnalysedProfile(): void
    {
        $platform = self::platform();
        $prompt = new Prompt($platform, self::analysedProfile());
        $prompt->markGenerated(PromptText::fromString('Old prompt.'));
        $latest = self::analysedProfile();

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->method('findByPlatform')->with($platform)->willReturn($prompt);
        $prompts->expects(self::once())->method('save')->with($prompt);

        $requested = self::service($prompts, self::cvProfilesFinding($latest))->requestGeneration($platform);

        self::assertSame($prompt, $requested);
        self::assertSame($latest, $prompt->getCvProfile());
        self::assertSame(LlmOperationStatus::Pending, $prompt->getGenerationStatus());
        self::assertSame('Old prompt.', $prompt->getText());
    }

    public function testRequestWithoutAnAnalysedProfileIsRejected(): void
    {
        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::never())->method('save');

        $this->expectException(AnalysedCvProfileNotFound::class);

        self::service($prompts, self::cvProfilesFinding(null))->requestGeneration(self::platform());
    }

    public function testRequestWhileGeneratingIsRejected(): void
    {
        $platform = self::platform();
        $prompt = new Prompt($platform, self::analysedProfile());

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->method('findByPlatform')->willReturn($prompt);
        $prompts->expects(self::never())->method('save');

        $this->expectException(PromptGenerationInProgress::class);

        self::service($prompts, self::cvProfilesFinding(self::analysedProfile()))->requestGeneration($platform);
    }

    public function testGenerationSendsThePlatformAndProfileAndSavesTheDraft(): void
    {
        $prompt = new Prompt(self::platform(), self::analysedProfile(
            new CvAnalysis(
                'Анализ',
                'A backend developer.',
                12,
                Seniority::Senior,
                SalaryExpectation::create(5000, 7000, 'EUR'),
                [
                    LanguageProficiency::create('en', LanguageLevel::C1),
                    LanguageProficiency::create('ru', LanguageLevel::Native),
                ],
            ),
            ['php', 'symfony'],
        ));

        $llm = $this->createMock(LlmServiceInterface::class);
        $llm->expects(self::once())
            ->method('generate')
            ->with(LlmTask::PromptGeneration, self::callback(static function (LlmRequest $request): bool {
                foreach ([
                    'from the Upwork platform',
                    'Seniority: senior',
                    'Years of experience: 12',
                    'Skills: php, symfony',
                    'Spoken languages: en (c1), ru (native)',
                    'Salary expectation per month: 5000-7000 EUR',
                    'A backend developer.',
                ] as $expected) {
                    self::assertStringContainsString($expected, $request->prompt);
                }

                self::assertMatchesRegularExpression(
                    '~<candidate_profile>\n.*A backend developer\.\n</candidate_profile>\s*$~s',
                    $request->prompt,
                );
                self::assertStringNotContainsString('Анализ', $request->prompt);
                self::assertNull($request->attachment);
                self::assertNull($request->responseSchema);

                return true;
            }))
            ->willReturn(new LlmResponse(" \n".self::DRAFT."\n"));

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::once())->method('save')->with($prompt);

        self::service($prompts, llm: $llm)->generate($prompt);

        self::assertSame(LlmOperationStatus::Done, $prompt->getGenerationStatus());
        self::assertSame(self::DRAFT, $prompt->getText());
        self::assertSame(PromptSource::Generated, $prompt->getSource());
    }

    public function testUnknownProfileFieldsAreSentAsNotStated(): void
    {
        $prompt = new Prompt(self::platform(), self::analysedProfile());

        $llm = $this->createMock(LlmServiceInterface::class);
        $llm->expects(self::once())
            ->method('generate')
            ->with(LlmTask::PromptGeneration, self::callback(static function (LlmRequest $request): bool {
                foreach ([
                    'Seniority: not stated',
                    'Years of experience: not stated',
                    'Skills: not stated',
                    'Spoken languages: not stated',
                    'Salary expectation per month: not stated',
                ] as $expected) {
                    self::assertStringContainsString($expected, $request->prompt);
                }

                return true;
            }))
            ->willReturn(new LlmResponse(self::DRAFT));

        self::service(llm: $llm)->generate($prompt);
    }

    #[DataProvider('salaries')]
    public function testOneSidedSalaryIsDescribed(SalaryExpectation $salary, string $expected): void
    {
        $prompt = new Prompt(self::platform(), self::analysedProfile(
            new CvAnalysis('Анализ', 'Analysis', null, null, $salary),
        ));

        $llm = $this->createMock(LlmServiceInterface::class);
        $llm->expects(self::once())
            ->method('generate')
            ->with(LlmTask::PromptGeneration, self::callback(
                static fn (LlmRequest $request): bool => str_contains($request->prompt, $expected),
            ))
            ->willReturn(new LlmResponse(self::DRAFT));

        self::service(llm: $llm)->generate($prompt);
    }

    /**
     * @return iterable<string, array{SalaryExpectation, string}>
     */
    public static function salaries(): iterable
    {
        yield 'minimum only' => [SalaryExpectation::create(5000, null, 'USD'), 'per month: from 5000 USD'];
        yield 'maximum only' => [SalaryExpectation::create(null, 7000, 'USD'), 'per month: up to 7000 USD'];
    }

    public function testFailedLlmRequestLeavesTheGenerationPendingForARetry(): void
    {
        $prompt = new Prompt(self::platform(), self::analysedProfile());
        $failure = LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 503');

        $llm = self::createStub(LlmServiceInterface::class);
        $llm->method('generate')->willThrowException($failure);

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::never())->method('save');

        try {
            self::service($prompts, llm: $llm)->generate($prompt);
            self::fail('Expected the LLM failure to propagate.');
        } catch (LlmRequestFailed $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(LlmOperationStatus::Pending, $prompt->getGenerationStatus());
    }

    /**
     * @param class-string<\Throwable> $expectedCause
     */
    #[DataProvider('permanentFailures')]
    public function testPermanentFailureMarksTheGenerationFailedKeepingTheOldText(
        ?\Throwable $llmFailure,
        string $expectedCause,
    ): void {
        $llm = self::createStub(LlmServiceInterface::class);
        if (null === $llmFailure) {
            $llm->method('generate')->willReturn(new LlmResponse(" \n\t"));
        } else {
            $llm->method('generate')->willThrowException($llmFailure);
        }

        $prompt = new Prompt(self::platform(), self::analysedProfile());
        $prompt->markGenerated(PromptText::fromString('Old prompt.'));
        $prompt->requestGeneration(self::analysedProfile());

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::once())->method('save')->with($prompt);

        try {
            self::service($prompts, llm: $llm)->generate($prompt);
            self::fail('Expected the generation to fail.');
        } catch (PromptGenerationFailed $exception) {
            self::assertInstanceOf($expectedCause, $exception->getPrevious());
        }

        self::assertSame(LlmOperationStatus::Failed, $prompt->getGenerationStatus());
        self::assertSame('Old prompt.', $prompt->getText());
        self::assertSame(PromptSource::Generated, $prompt->getSource());
    }

    /**
     * @return iterable<string, array{?\Throwable, class-string<\Throwable>}>
     */
    public static function permanentFailures(): iterable
    {
        yield 'no model selected' => [
            LlmModelNotSelected::forTask(LlmTask::PromptGeneration),
            LlmModelNotSelected::class,
        ];
        yield 'no client' => [LlmClientNotFound::forProvider(LlmProvider::Gemini), LlmClientNotFound::class];
        yield 'no api key' => [ApiKeyNotSet::forProvider(LlmProvider::Gemini), ApiKeyNotSet::class];
        yield 'undecryptable api key' => [ApiKeyDecryptionFailed::create(), ApiKeyDecryptionFailed::class];
        yield 'no encryption key' => [InvalidEncryptionKey::notSet(), InvalidEncryptionKey::class];
        yield 'blank response' => [null, EmptyPromptText::class];
    }

    public function testFinishedGenerationIsNotRunAgain(): void
    {
        $prompt = new Prompt(self::platform(), self::analysedProfile());
        $prompt->markGenerated(PromptText::fromString('Old prompt.'));

        $llm = $this->createMock(LlmServiceInterface::class);
        $llm->expects(self::never())->method('generate');

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::never())->method('save');

        $this->expectException(PromptGenerationAlreadyFinished::class);

        self::service($prompts, llm: $llm)->generate($prompt);
    }

    public function testGivingUpMarksAPendingGenerationFailed(): void
    {
        $prompt = new Prompt(self::platform(), self::analysedProfile());

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::once())->method('save')->with($prompt);

        self::service($prompts)->giveUpGeneration($prompt);

        self::assertSame(LlmOperationStatus::Failed, $prompt->getGenerationStatus());
    }

    public function testGivingUpLeavesAFinishedGenerationAsItIs(): void
    {
        $prompt = new Prompt(self::platform(), self::analysedProfile());
        $prompt->markGenerated(PromptText::fromString(self::DRAFT));

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::never())->method('save');

        self::service($prompts)->giveUpGeneration($prompt);

        self::assertSame(LlmOperationStatus::Done, $prompt->getGenerationStatus());
        self::assertSame(self::DRAFT, $prompt->getText());
    }

    private static function service(
        ?PromptRepositoryInterface $prompts = null,
        ?CvProfileRepositoryInterface $cvProfiles = null,
        ?LlmServiceInterface $llm = null,
    ): PromptService {
        return new PromptService(
            $prompts ?? self::createStub(PromptRepositoryInterface::class),
            $cvProfiles ?? self::createStub(CvProfileRepositoryInterface::class),
            $llm ?? self::createStub(LlmServiceInterface::class),
        );
    }

    private static function cvProfilesFinding(?CvProfile $cvProfile): CvProfileRepositoryInterface
    {
        $cvProfiles = self::createStub(CvProfileRepositoryInterface::class);
        $cvProfiles->method('findLatestAnalysed')->willReturn($cvProfile);

        return $cvProfiles;
    }

    /**
     * @param list<non-empty-string> $skillNames
     */
    private static function analysedProfile(?CvAnalysis $analysis = null, array $skillNames = []): CvProfile
    {
        $cvProfile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $cvProfile->markAnalysisDone(
            $analysis ?? new CvAnalysis('Анализ', 'Analysis', null, null, null),
            array_map(static fn (string $name): Skill => new Skill(SkillName::fromString($name)), $skillNames),
            '{}',
        );

        return $cvProfile;
    }

    private static function platform(): Platform
    {
        return new Platform('Upwork', PlatformAccessType::Browser);
    }
}
