<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Llm\LlmProvider;
use App\Domain\Platform\PlatformAccessType;
use App\Domain\Prompt\PromptGenerationAlreadyFinished;
use App\Domain\Prompt\PromptGenerationFailed;
use App\Domain\Prompt\PromptServiceInterface;
use App\Entity\CvProfile;
use App\Entity\Platform;
use App\Entity\Prompt;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Message\GeneratePrompt;
use App\MessageHandler\GeneratePromptHandler;
use App\Repository\PromptRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class GeneratePromptHandlerTest extends TestCase
{
    private const int ID = 7;

    public function testGeneratesThePromptLoadedById(): void
    {
        $prompt = self::prompt();

        $promptService = $this->createMock(PromptServiceInterface::class);
        $promptService->expects(self::once())->method('generate')->with($prompt);

        self::handler(self::repositoryFinding($prompt), $promptService)(new GeneratePrompt(self::ID));
    }

    public function testAMissingPromptIsNotRetried(): void
    {
        $promptService = $this->createMock(PromptServiceInterface::class);
        $promptService->expects(self::never())->method('generate');

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('The prompt 7 does not exist.');

        self::handler(self::repositoryFinding(null), $promptService)(new GeneratePrompt(self::ID));
    }

    public function testAFailedGenerationIsNotRetried(): void
    {
        $failure = PromptGenerationFailed::because(new \RuntimeException('no model selected'));

        $promptService = self::createStub(PromptServiceInterface::class);
        $promptService->method('generate')->willThrowException($failure);

        try {
            self::handler(self::repositoryFinding(self::prompt()), $promptService)(new GeneratePrompt(self::ID));
            self::fail('Expected the failed generation not to be retried.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testAFailedLlmRequestPropagatesForARetry(): void
    {
        $failure = LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 503');

        $promptService = self::createStub(PromptServiceInterface::class);
        $promptService->method('generate')->willThrowException($failure);

        try {
            self::handler(self::repositoryFinding(self::prompt()), $promptService)(new GeneratePrompt(self::ID));
            self::fail('Expected the failed LLM request to propagate.');
        } catch (LlmRequestFailed $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testAnAlreadyFinishedGenerationIsAcknowledged(): void
    {
        $promptService = $this->createMock(PromptServiceInterface::class);
        $promptService->expects(self::once())
            ->method('generate')
            ->willThrowException(PromptGenerationAlreadyFinished::withStatus(LlmOperationStatus::Done));

        self::handler(self::repositoryFinding(self::prompt()), $promptService)(new GeneratePrompt(self::ID));
    }

    private static function handler(
        PromptRepositoryInterface $prompts,
        PromptServiceInterface $promptService,
    ): GeneratePromptHandler {
        return new GeneratePromptHandler($prompts, $promptService);
    }

    private function repositoryFinding(?Prompt $prompt): PromptRepositoryInterface
    {
        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::once())->method('findById')->with(self::ID)->willReturn($prompt);

        return $prompts;
    }

    private static function prompt(): Prompt
    {
        $cvProfile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $cvProfile->markAnalysisDone(new CvAnalysis('Анализ', 'Analysis', null, null, null), [], '{}');

        return new Prompt(new Platform('Upwork', PlatformAccessType::Browser), $cvProfile);
    }
}
