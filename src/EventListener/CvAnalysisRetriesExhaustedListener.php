<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\Cv\CvServiceInterface;
use App\Message\AnalyzeCv;
use App\Repository\CvProfileRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Without it a CV whose LLM request failed on every retry would stay pending forever.
 *
 * Must run after Messenger's retry listener (priority 100), which decides willRetry(). The worker resets services
 * only after this event, so a DB error in the handler may have closed the entity manager, and the identity map may
 * hold the profile as the handler left it: the manager is reset and the profile loaded again.
 */
#[AsEventListener(priority: 50)]
final readonly class CvAnalysisRetriesExhaustedListener
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private CvProfileRepositoryInterface $cvProfiles,
        private CvServiceInterface $cvService,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof AnalyzeCv || $event->willRetry()) {
            return;
        }

        $this->doctrine->resetManager();

        $cvProfile = $this->cvProfiles->findById($message->cvProfileId);
        if (null !== $cvProfile) {
            $this->cvService->giveUpAnalysis($cvProfile);
        }
    }
}
