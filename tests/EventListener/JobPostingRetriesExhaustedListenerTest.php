<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingServiceInterface;
use App\Domain\Llm\LlmFailure;
use App\Domain\Llm\LlmFailureCause;
use App\Domain\Llm\LlmProvider;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\EventListener\JobPostingRetriesExhaustedListener;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Message\ProcessJobPosting;
use App\Repository\JobPostingRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class JobPostingRetriesExhaustedListenerTest extends TestCase
{
    private const int ID = 7;

    public function testAfterTheLastRetryGivesUpThePostingReloadedFromAResetEntityManager(): void
    {
        $posting = self::posting();
        $calls = [];

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::once())
            ->method('resetManager')
            ->willReturnCallback(function () use (&$calls): ObjectManager {
                $calls[] = 'reset';

                return self::createStub(ObjectManager::class);
            });

        $postings = $this->createMock(JobPostingRepositoryInterface::class);
        $postings->expects(self::once())
            ->method('findById')
            ->with(self::ID)
            ->willReturnCallback(static function () use (&$calls, $posting): JobPosting {
                $calls[] = 'find';

                return $posting;
            });

        $postingService = $this->createMock(JobPostingServiceInterface::class);
        $postingService->expects(self::once())
            ->method('giveUpProcessing')
            ->with($posting, LlmFailure::create(LlmFailureCause::Unexpected))
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'give up';
            });

        new JobPostingRetriesExhaustedListener($doctrine, $postings, $postingService)(
            self::failedEvent(new ProcessJobPosting(self::ID)),
        );

        self::assertSame(['reset', 'find', 'give up'], $calls);
    }

    public function testGivesUpWithTheProviderFailureOfTheLastAttempt(): void
    {
        $failure = LlmRequestFailed::transient(LlmProvider::Gemini, 'HTTP 429: quota exceeded');

        $this->assertGivesUpWith(
            new HandlerFailedException(new Envelope(new ProcessJobPosting(self::ID)), [$failure]),
            LlmFailure::create(LlmFailureCause::Provider, $failure->getMessage()),
        );
    }

    public function testGivesUpAtOnceWithThePermanentProviderFailure(): void
    {
        $failure = LlmRequestFailed::permanent(LlmProvider::Gemini, 'HTTP 403: outstanding balance');

        $this->assertGivesUpWith(
            new HandlerFailedException(
                new Envelope(new ProcessJobPosting(self::ID)),
                [new UnrecoverableMessageHandlingException($failure->getMessage(), 0, $failure)],
            ),
            LlmFailure::create(LlmFailureCause::Provider, $failure->getMessage()),
        );
    }

    public function testAMissingPostingIsSkipped(): void
    {
        $postings = $this->createMock(JobPostingRepositoryInterface::class);
        $postings->expects(self::once())->method('findById')->with(self::ID)->willReturn(null);

        $postingService = $this->createMock(JobPostingServiceInterface::class);
        $postingService->expects(self::never())->method('giveUpProcessing');

        new JobPostingRetriesExhaustedListener(
            self::createStub(ManagerRegistry::class),
            $postings,
            $postingService,
        )(self::failedEvent(new ProcessJobPosting(self::ID)));
    }

    public function testDoesNothingWhileTheMessageWillBeRetried(): void
    {
        $this->assertIgnored(self::failedEvent(new ProcessJobPosting(self::ID), willRetry: true));
    }

    public function testIgnoresOtherMessages(): void
    {
        $this->assertIgnored(self::failedEvent(new \stdClass()));
    }

    private function assertGivesUpWith(\Throwable $throwable, LlmFailure $expected): void
    {
        $posting = self::posting();
        $postings = self::createStub(JobPostingRepositoryInterface::class);
        $postings->method('findById')->willReturn($posting);

        $postingService = $this->createMock(JobPostingServiceInterface::class);
        $postingService->expects(self::once())->method('giveUpProcessing')->with($posting, $expected);

        new JobPostingRetriesExhaustedListener(
            self::createStub(ManagerRegistry::class),
            $postings,
            $postingService,
        )(self::failedEvent(new ProcessJobPosting(self::ID), throwable: $throwable));
    }

    private function assertIgnored(WorkerMessageFailedEvent $event): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('resetManager');

        $postings = $this->createMock(JobPostingRepositoryInterface::class);
        $postings->expects(self::never())->method('findById');

        $postingService = $this->createMock(JobPostingServiceInterface::class);
        $postingService->expects(self::never())->method('giveUpProcessing');

        new JobPostingRetriesExhaustedListener($doctrine, $postings, $postingService)($event);
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

    private static function posting(): JobPosting
    {
        return new JobPosting(
            new Platform('Upwork', PlatformAccessType::Browser),
            JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.upwork.com/jobs/1'),
            new \DateTimeImmutable('2026-10-14 09:00'),
        );
    }
}
