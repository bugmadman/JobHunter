<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Domain\Cv\CvFile;
use App\Domain\Cv\CvServiceInterface;
use App\Domain\Llm\LlmFailure;
use App\Domain\Llm\LlmFailureCause;
use App\Domain\Llm\LlmProvider;
use App\Entity\CvProfile;
use App\EventListener\CvAnalysisRetriesExhaustedListener;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Message\AnalyzeCv;
use App\Repository\CvProfileRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class CvAnalysisRetriesExhaustedListenerTest extends TestCase
{
    private const int ID = 7;

    public function testAfterTheLastRetryGivesUpTheProfileReloadedFromAResetEntityManager(): void
    {
        $profile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $calls = [];

        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::once())
            ->method('resetManager')
            ->willReturnCallback(function () use (&$calls): ObjectManager {
                $calls[] = 'reset';

                return self::createStub(ObjectManager::class);
            });

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())
            ->method('findById')
            ->with(self::ID)
            ->willReturnCallback(static function () use (&$calls, $profile): CvProfile {
                $calls[] = 'find';

                return $profile;
            });

        $cvService = $this->createMock(CvServiceInterface::class);
        $cvService->expects(self::once())
            ->method('giveUpAnalysis')
            ->with($profile, LlmFailure::create(LlmFailureCause::Unexpected))
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'give up';
            });

        new CvAnalysisRetriesExhaustedListener($doctrine, $cvProfiles, $cvService)(
            self::failedEvent(new AnalyzeCv(self::ID)),
        );

        self::assertSame(['reset', 'find', 'give up'], $calls);
    }

    public function testGivesUpWithTheProviderFailureOfTheLastAttempt(): void
    {
        $profile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $failure = LlmRequestFailed::transient(LlmProvider::Gemini, 'HTTP 429: quota exceeded');

        $cvProfiles = self::createStub(CvProfileRepositoryInterface::class);
        $cvProfiles->method('findById')->willReturn($profile);

        $cvService = $this->createMock(CvServiceInterface::class);
        $cvService->expects(self::once())
            ->method('giveUpAnalysis')
            ->with($profile, LlmFailure::create(LlmFailureCause::Provider, $failure->getMessage()));

        $message = new AnalyzeCv(self::ID);
        new CvAnalysisRetriesExhaustedListener(self::createStub(ManagerRegistry::class), $cvProfiles, $cvService)(
            self::failedEvent($message, throwable: new HandlerFailedException(new Envelope($message), [$failure])),
        );
    }

    public function testAMissingProfileIsSkipped(): void
    {
        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('findById')->with(self::ID)->willReturn(null);

        $cvService = $this->createMock(CvServiceInterface::class);
        $cvService->expects(self::never())->method('giveUpAnalysis');

        new CvAnalysisRetriesExhaustedListener(self::createStub(ManagerRegistry::class), $cvProfiles, $cvService)(
            self::failedEvent(new AnalyzeCv(self::ID)),
        );
    }

    public function testDoesNothingWhileTheMessageWillBeRetried(): void
    {
        $this->assertIgnored(self::failedEvent(new AnalyzeCv(self::ID), willRetry: true));
    }

    public function testIgnoresOtherMessages(): void
    {
        $this->assertIgnored(self::failedEvent(new \stdClass()));
    }

    private function assertIgnored(WorkerMessageFailedEvent $event): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->expects(self::never())->method('resetManager');

        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::never())->method('findById');

        $cvService = $this->createMock(CvServiceInterface::class);
        $cvService->expects(self::never())->method('giveUpAnalysis');

        new CvAnalysisRetriesExhaustedListener($doctrine, $cvProfiles, $cvService)($event);
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
}
