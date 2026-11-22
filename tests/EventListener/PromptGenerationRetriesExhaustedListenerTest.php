<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Domain\Llm\LlmFailure;
use App\Domain\Llm\LlmFailureCause;
use App\Domain\Llm\LlmProvider;
use App\Domain\Platform\PlatformAccessType;
use App\Domain\Prompt\PromptServiceInterface;
use App\Entity\CvProfile;
use App\Entity\Platform;
use App\Entity\Prompt;
use App\EventListener\PromptGenerationRetriesExhaustedListener;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Message\GeneratePrompt;
use App\Repository\PromptRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class PromptGenerationRetriesExhaustedListenerTest extends TestCase
{
    private const int ID = 7;

    public function testAfterTheLastRetryGivesUpThePromptReloadedFromAResetEntityManager(): void
    {
        $prompt = self::prompt();
        $calls = [];

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::once())
            ->method('resetManager')
            ->willReturnCallback(function () use (&$calls): ObjectManager {
                $calls[] = 'reset';

                return self::createStub(ObjectManager::class);
            });

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::once())
            ->method('findById')
            ->with(self::ID)
            ->willReturnCallback(static function () use (&$calls, $prompt): Prompt {
                $calls[] = 'find';

                return $prompt;
            });

        $promptService = $this->createMock(PromptServiceInterface::class);
        $promptService->expects(self::once())
            ->method('giveUpGeneration')
            ->with($prompt, LlmFailure::create(LlmFailureCause::Unexpected))
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'give up';
            });

        new PromptGenerationRetriesExhaustedListener($doctrine, $prompts, $promptService)(
            self::failedEvent(new GeneratePrompt(self::ID)),
        );

        self::assertSame(['reset', 'find', 'give up'], $calls);
    }

    public function testGivesUpWithTheProviderFailureOfTheLastAttempt(): void
    {
        $prompt = self::prompt();
        $failure = LlmRequestFailed::transient(LlmProvider::Gemini, 'HTTP 429: quota exceeded');

        $this->assertGivesUpWith(
            $prompt,
            new HandlerFailedException(new Envelope(new GeneratePrompt(self::ID)), [$failure]),
            LlmFailure::create(LlmFailureCause::Provider, $failure->getMessage()),
        );
    }

    public function testGivesUpAtOnceWithThePermanentProviderFailure(): void
    {
        $prompt = self::prompt();
        $failure = LlmRequestFailed::permanent(LlmProvider::Gemini, 'HTTP 403: outstanding balance');

        $this->assertGivesUpWith(
            $prompt,
            new HandlerFailedException(
                new Envelope(new GeneratePrompt(self::ID)),
                [new UnrecoverableMessageHandlingException($failure->getMessage(), 0, $failure)],
            ),
            LlmFailure::create(LlmFailureCause::Provider, $failure->getMessage()),
        );
    }

    public function testAMissingPromptIsSkipped(): void
    {
        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::once())->method('findById')->with(self::ID)->willReturn(null);

        $promptService = $this->createMock(PromptServiceInterface::class);
        $promptService->expects(self::never())->method('giveUpGeneration');

        new PromptGenerationRetriesExhaustedListener(
            self::createStub(ManagerRegistry::class),
            $prompts,
            $promptService,
        )(self::failedEvent(new GeneratePrompt(self::ID)));
    }

    public function testDoesNothingWhileTheMessageWillBeRetried(): void
    {
        $this->assertIgnored(self::failedEvent(new GeneratePrompt(self::ID), willRetry: true));
    }

    public function testIgnoresOtherMessages(): void
    {
        $this->assertIgnored(self::failedEvent(new \stdClass()));
    }

    private function assertGivesUpWith(Prompt $prompt, \Throwable $throwable, LlmFailure $expected): void
    {
        $prompts = self::createStub(PromptRepositoryInterface::class);
        $prompts->method('findById')->willReturn($prompt);

        $promptService = $this->createMock(PromptServiceInterface::class);
        $promptService->expects(self::once())->method('giveUpGeneration')->with($prompt, $expected);

        new PromptGenerationRetriesExhaustedListener(
            self::createStub(ManagerRegistry::class),
            $prompts,
            $promptService,
        )(self::failedEvent(new GeneratePrompt(self::ID), throwable: $throwable));
    }

    private function assertIgnored(WorkerMessageFailedEvent $event): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('resetManager');

        $prompts = $this->createMock(PromptRepositoryInterface::class);
        $prompts->expects(self::never())->method('findById');

        $promptService = $this->createMock(PromptServiceInterface::class);
        $promptService->expects(self::never())->method('giveUpGeneration');

        new PromptGenerationRetriesExhaustedListener($doctrine, $prompts, $promptService)($event);
    }

    private static function failedEvent(
        object $message,
        bool $willRetry = false,
        \Throwable $throwable = new \RuntimeException('failed'),
    ): WorkerMessageFailedEvent {
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'async', $throwable);
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    private static function prompt(): Prompt
    {
        $cvProfile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $cvProfile->markAnalysisDone(new CvAnalysis('Analysis', null, null, null), [], '{}');

        return new Prompt(new Platform('Upwork', PlatformAccessType::Browser), $cvProfile);
    }
}
