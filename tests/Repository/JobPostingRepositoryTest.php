<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingListFilter;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Domain\JobPosting\StatusReason;
use App\Entity\JobPosting;
use App\Entity\JobPostingStageLog;
use App\Entity\Platform;
use App\Repository\JobPostingRepository;
use App\Repository\PlatformRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JobPostingRepositoryTest extends KernelTestCase
{
    public function testListsThePostingsNewestFirst(): void
    {
        $this->repository()->save($this->posting('Older', '2026-10-13 09:00'));
        $this->repository()->save($this->posting('Newest', '2026-10-14 09:00'));
        $this->repository()->save($this->posting('Middle', '2026-10-13 18:00'));

        self::assertSame(['Newest', 'Middle', 'Older'], $this->titles(new JobPostingListFilter()));
    }

    public function testFiltersByPlatformStatusAndVerdict(): void
    {
        $liked = $this->posting('Liked', '2026-10-14 09:00');
        $this->evaluate($liked, JobPostingVerdict::Like);
        $this->repository()->save($liked);
        $disliked = $this->posting('Disliked', '2026-10-14 08:00');
        $this->evaluate($disliked, JobPostingVerdict::Dislike);
        $this->repository()->save($disliked);
        $this->repository()->save($this->posting('New', '2026-10-14 07:00'));
        $this->repository()->save($this->posting('LinkedIn', '2026-10-14 06:00', 'LinkedIn'));

        $upworkId = $this->platform('Upwork')->getId();

        self::assertSame(['Liked', 'Disliked', 'New'], $this->titles(new JobPostingListFilter($upworkId)));
        self::assertSame(['New', 'LinkedIn'], $this->titles(new JobPostingListFilter(status: JobPostingStatus::Raw)));
        self::assertSame(
            ['Disliked'],
            $this->titles(new JobPostingListFilter($upworkId, JobPostingStatus::Evaluated, JobPostingVerdict::Dislike)),
        );
    }

    public function testListsOnlyThePostingsStageLogsOldestFirst(): void
    {
        $posting = $this->posting('Rejected', '2026-10-14 10:00');
        $this->repository()->save($posting);
        $posting->markRejected(StatusReason::fromString('No skills.'), new \DateTimeImmutable('2026-10-14 10:05'));
        $this->repository()->save($posting);
        $this->repository()->save($this->posting('Other', '2026-10-14 10:00'));

        $history = array_map(
            static fn (JobPostingStageLog $log): array => [$log->getFrom(), $log->getTo(), $log->getReason()],
            $this->repository()->findStageLogsOldestFirst($posting),
        );

        self::assertSame(
            [
                [null, JobPostingStatus::Raw, null],
                [JobPostingStatus::Raw, JobPostingStatus::Rejected, 'No skills.'],
            ],
            $history,
        );
    }

    /**
     * @return list<string>
     */
    private function titles(JobPostingListFilter $filter): array
    {
        return array_map(
            static fn (JobPosting $posting): string => $posting->getTitle(),
            $this->repository()->findNewestFirst($filter),
        );
    }

    private function evaluate(JobPosting $posting, JobPostingVerdict $verdict): void
    {
        $at = new \DateTimeImmutable('2026-10-14 10:00');
        $posting->markFiltered($at);
        $posting->markEvaluated($verdict, StatusReason::fromString('Reason.'), $at);
    }

    private function posting(string $title, string $createdAt, string $platform = 'Upwork'): JobPosting
    {
        return new JobPosting(
            $this->platform($platform),
            JobPostingDetails::create($title, 'Description.', 'https://example.com/'.$title),
            new \DateTimeImmutable($createdAt),
        );
    }

    private function platform(string $name): Platform
    {
        $platforms = self::getContainer()->get(PlatformRepository::class);
        self::assertInstanceOf(PlatformRepository::class, $platforms);
        $platform = $platforms->findByName($name);
        self::assertInstanceOf(Platform::class, $platform);

        return $platform;
    }

    private function repository(): JobPostingRepository
    {
        $repository = self::getContainer()->get(JobPostingRepository::class);
        self::assertInstanceOf(JobPostingRepository::class, $repository);

        return $repository;
    }
}
