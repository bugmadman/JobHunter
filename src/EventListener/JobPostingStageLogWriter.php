<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\JobPosting;
use App\Entity\JobPostingStageLog;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Writes the posting's released events in the same flush as its status, so the log can't miss a transition. An
 * entity persisted during onFlush is not inserted unless its change set is computed by hand.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class JobPostingStageLogWriter
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata = $entityManager->getClassMetadata(JobPostingStageLog::class);

        $entities = [...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()];
        foreach ($entities as $posting) {
            if (!$posting instanceof JobPosting) {
                continue;
            }

            foreach ($posting->releaseEvents() as $event) {
                $log = new JobPostingStageLog($posting, $event->from, $event->to, $event->reason, $event->occurredAt);
                $entityManager->persist($log);
                $unitOfWork->computeChangeSet($metadata, $log);
            }
        }
    }
}
