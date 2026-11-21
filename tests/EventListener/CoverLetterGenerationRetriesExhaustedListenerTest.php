<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Domain\CoverLetter\CoverLetterServiceInterface;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\Llm\LlmFailure;
use App\Domain\Llm\LlmFailureCause;
use App\Domain\Llm\LlmProvider;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\CoverLetter;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\EventListener\CoverLetterGenerationRetriesExhaustedListener;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Message\GenerateCoverLetter;
use App\Repository\CoverLetterRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class CoverLetterGenerationRetriesExhaustedListenerTest extends TestCase
{
    private const int ID = 7;

    public function testAfterTheLastRetryGivesUpTheLetterReloadedFromAResetEntityManager(): void
    {
        $coverLetter = self::coverLetter();
        $calls = [];

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::once())
            ->method('resetManager')
            ->willReturnCallback(function () use (&$calls): ObjectManager {
                $calls[] = 'reset';

                return self::createStub(ObjectManager::class);
            });

        $coverLetters = $this->createMock(CoverLetterRepositoryInterface::class);
        $coverLetters->expects(self::once())
            ->method('findById')
            ->with(self::ID)
            ->willReturnCallback(static function () use (&$calls, $coverLetter): CoverLetter {
                $calls[] = 'find';

                return $coverLetter;
            });

        $coverLetterService = $this->createMock(CoverLetterServiceInterface::class);
        $coverLetterService->expects(self::once())
            ->method('giveUpGeneration')
            ->with($coverLetter, LlmFailure::create(LlmFailureCause::Unexpected))
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'give up';
            });

        new CoverLetterGenerationRetriesExhaustedListener($doctrine, $coverLetters, $coverLetterService)(
            self::failedEvent(new GenerateCoverLetter(self::ID)),
        );

        self::assertSame(['reset', 'find', 'give up'], $calls);
    }

    public function testGivesUpWithTheProviderFailureOfTheLastAttempt(): void
    {
        $coverLetter = self::coverLetter();
        $failure = LlmRequestFailed::transient(LlmProvider::Gemini, 'HTTP 429: quota exceeded');

        $this->assertGivesUpWith(
            $coverLetter,
            new HandlerFailedException(new Envelope(new GenerateCoverLetter(self::ID)), [$failure]),
            LlmFailure::create(LlmFailureCause::Provider, $failure->getMessage()),
        );
    }

    public function testGivesUpAtOnceWithThePermanentProviderFailure(): void
    {
        $coverLetter = self::coverLetter();
        $failure = LlmRequestFailed::permanent(LlmProvider::Gemini, 'HTTP 403: outstanding balance');

        $this->assertGivesUpWith(
            $coverLetter,
            new HandlerFailedException(
                new Envelope(new GenerateCoverLetter(self::ID)),
                [new UnrecoverableMessageHandlingException($failure->getMessage(), 0, $failure)],
            ),
            LlmFailure::create(LlmFailureCause::Provider, $failure->getMessage()),
        );
    }

    public function testAMissingLetterIsSkipped(): void
    {
        $coverLetters = $this->createMock(CoverLetterRepositoryInterface::class);
        $coverLetters->expects(self::once())->method('findById')->with(self::ID)->willReturn(null);

        $coverLetterService = $this->createMock(CoverLetterServiceInterface::class);
        $coverLetterService->expects(self::never())->method('giveUpGeneration');

        new CoverLetterGenerationRetriesExhaustedListener(
            self::createStub(ManagerRegistry::class),
            $coverLetters,
            $coverLetterService,
        )(self::failedEvent(new GenerateCoverLetter(self::ID)));
    }

    public function testDoesNothingWhileTheMessageWillBeRetried(): void
    {
        $this->assertIgnored(self::failedEvent(new GenerateCoverLetter(self::ID), willRetry: true));
    }

    public function testIgnoresOtherMessages(): void
    {
        $this->assertIgnored(self::failedEvent(new \stdClass()));
    }

    private function assertGivesUpWith(CoverLetter $coverLetter, \Throwable $throwable, LlmFailure $expected): void
    {
        $coverLetters = self::createStub(CoverLetterRepositoryInterface::class);
        $coverLetters->method('findById')->willReturn($coverLetter);

        $coverLetterService = $this->createMock(CoverLetterServiceInterface::class);
        $coverLetterService->expects(self::once())->method('giveUpGeneration')->with($coverLetter, $expected);

        new CoverLetterGenerationRetriesExhaustedListener(
            self::createStub(ManagerRegistry::class),
            $coverLetters,
            $coverLetterService,
        )(self::failedEvent(new GenerateCoverLetter(self::ID), throwable: $throwable));
    }

    private function assertIgnored(WorkerMessageFailedEvent $event): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('resetManager');

        $coverLetters = $this->createMock(CoverLetterRepositoryInterface::class);
        $coverLetters->expects(self::never())->method('findById');

        $coverLetterService = $this->createMock(CoverLetterServiceInterface::class);
        $coverLetterService->expects(self::never())->method('giveUpGeneration');

        new CoverLetterGenerationRetriesExhaustedListener($doctrine, $coverLetters, $coverLetterService)($event);
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

    private static function coverLetter(): CoverLetter
    {
        return new CoverLetter(
            new JobPosting(
                new Platform('Upwork', PlatformAccessType::Browser),
                JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.upwork.com/jobs/1'),
                new \DateTimeImmutable('2026-10-14 09:00'),
            ),
            new \DateTimeImmutable('2026-10-14 12:00'),
        );
    }
}
