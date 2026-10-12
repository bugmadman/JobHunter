<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Domain\JobPosting\StatusReason;
use App\Entity\JobPosting;
use App\Entity\JobPostingStageLog;
use App\Entity\Platform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JobPostingStageLogWriterTest extends KernelTestCase
{
    public function testNewPostingIsLoggedWithItsArrivalAndTransitionsInTheSameFlush(): void
    {
        $posting = $this->newPosting();
        $posting->markRejected(
            StatusReason::fromString('No "PHP" in the title.'),
            new \DateTimeImmutable('2026-10-12 09:31:00'),
        );

        $this->entityManager()->persist($posting);
        $this->entityManager()->flush();

        self::assertSame(
            [
                [null, 'raw', null, '2026-10-12 09:30:00'],
                ['raw', 'rejected', 'No "PHP" in the title.', '2026-10-12 09:31:00'],
            ],
            $this->loggedTransitions($posting),
        );
    }

    public function testStoredPostingLogsOnlyItsNewTransitions(): void
    {
        $posting = $this->newPosting();
        $this->entityManager()->persist($posting);
        $this->entityManager()->flush();

        $posting->markFiltered(new \DateTimeImmutable('2026-10-12 09:31:00'));
        $this->entityManager()->flush();
        $posting->markEvaluated(
            JobPostingVerdict::Like,
            StatusReason::fromString('Symfony and remote.'),
            new \DateTimeImmutable('2026-10-12 09:45:00'),
        );
        $this->entityManager()->flush();
        $this->entityManager()->flush();

        self::assertSame(
            [
                [null, 'raw', null, '2026-10-12 09:30:00'],
                ['raw', 'filtered', null, '2026-10-12 09:31:00'],
                ['filtered', 'evaluated', 'Symfony and remote.', '2026-10-12 09:45:00'],
            ],
            $this->loggedTransitions($posting),
        );
    }

    private function newPosting(): JobPosting
    {
        $platform = $this->entityManager()->getRepository(Platform::class)->findOneBy(['name' => 'LinkedIn']);
        self::assertInstanceOf(Platform::class, $platform);

        return new JobPosting(
            $platform,
            JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.linkedin.com/jobs/view/456/'),
            new \DateTimeImmutable('2026-10-12 09:30:00'),
        );
    }

    /**
     * Read back from the DB, not the identity map.
     *
     * @return list<array{?string, string, ?string, string}>
     */
    private function loggedTransitions(JobPosting $posting): array
    {
        $postingId = $posting->getId();
        $this->entityManager()->clear();

        return array_map(
            static fn (JobPostingStageLog $log): array => [
                $log->getFrom()?->value,
                $log->getTo()->value,
                $log->getReason(),
                $log->getOccurredAt()->format('Y-m-d H:i:s'),
            ],
            $this->entityManager()->getRepository(JobPostingStageLog::class)->findBy(
                ['jobPosting' => $postingId],
                ['id' => 'ASC'],
            ),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
