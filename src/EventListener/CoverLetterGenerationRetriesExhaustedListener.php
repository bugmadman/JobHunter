<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\CoverLetter\CoverLetterServiceInterface;
use App\Message\GenerateCoverLetter;
use App\Repository\CoverLetterRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Without it a letter whose LLM request failed on every retry would stay pending, blocking a new request for the
 * job posting for good.
 *
 * Must run after Messenger's retry listener (priority 100), which decides willRetry(). The worker resets services
 * only after this event, so a DB error in the handler may have closed the entity manager, and the identity map may
 * hold the letter as the handler left it: the manager is reset and the letter loaded again.
 */
#[AsEventListener(priority: 50)]
final readonly class CoverLetterGenerationRetriesExhaustedListener
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private CoverLetterRepositoryInterface $coverLetters,
        private CoverLetterServiceInterface $coverLetterService,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof GenerateCoverLetter || $event->willRetry()) {
            return;
        }

        $this->doctrine->resetManager();

        $coverLetter = $this->coverLetters->findById($message->coverLetterId);
        if (null !== $coverLetter) {
            $this->coverLetterService->giveUpGeneration($coverLetter);
        }
    }
}
