<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\JobPosting\JobPostingServiceInterface;
use App\Domain\Llm\LlmFailure;
use App\Message\ProcessJobPosting;
use App\Repository\JobPostingRepositoryInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Without it a posting whose matching request failed on every retry, or permanently, would stay filtered for good,
 * never reaching a final status nor showing why.
 *
 * Must run after Messenger's retry listener (priority 100), which decides willRetry(). The worker resets services
 * only after this event, so a DB error in the handler may have closed the entity manager, and the identity map may
 * hold the posting as the handler left it: the manager is reset and the posting loaded again.
 */
#[AsEventListener(priority: 50)]
final readonly class JobPostingRetriesExhaustedListener
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private JobPostingRepositoryInterface $postings,
        private JobPostingServiceInterface $postingService,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ProcessJobPosting || $event->willRetry()) {
            return;
        }

        $this->doctrine->resetManager();

        $posting = $this->postings->findById($message->jobPostingId);
        if (null !== $posting) {
            $this->postingService->giveUpProcessing($posting, LlmFailure::fromThrowable($event->getThrowable()));
        }
    }
}
