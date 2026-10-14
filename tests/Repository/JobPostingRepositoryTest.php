<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\JobPosting\JobPostingDetails;
use App\Entity\JobPosting;
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

        self::assertSame(
            ['Newest', 'Middle', 'Older'],
            array_map(
                static fn (JobPosting $posting): string => $posting->getTitle(),
                $this->repository()->findAllNewestFirst(),
            ),
        );
    }

    private function posting(string $title, string $createdAt): JobPosting
    {
        return new JobPosting(
            $this->upwork(),
            JobPostingDetails::create($title, 'Description.', 'https://example.com/'.$title),
            new \DateTimeImmutable($createdAt),
        );
    }

    private function upwork(): Platform
    {
        $platforms = self::getContainer()->get(PlatformRepository::class);
        self::assertInstanceOf(PlatformRepository::class, $platforms);
        $upwork = $platforms->findByName('Upwork');
        self::assertInstanceOf(Platform::class, $upwork);

        return $upwork;
    }

    private function repository(): JobPostingRepository
    {
        $repository = self::getContainer()->get(JobPostingRepository::class);
        self::assertInstanceOf(JobPostingRepository::class, $repository);

        return $repository;
    }
}
