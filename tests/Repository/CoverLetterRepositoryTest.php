<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\JobPosting\JobPostingDetails;
use App\Entity\CoverLetter;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Repository\CoverLetterRepository;
use App\Repository\JobPostingRepository;
use App\Repository\PlatformRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CoverLetterRepositoryTest extends KernelTestCase
{
    public function testFindsTheNewestLetterOfThePosting(): void
    {
        $posting = $this->posting('Php');
        $other = $this->posting('Go');
        $this->save($posting, '2026-10-14 10:00');
        $newest = $this->save($posting, '2026-10-14 12:00');
        $this->save($posting, '2026-10-14 11:00');
        $this->save($other, '2026-10-14 13:00');

        self::assertSame($newest, $this->repository()->findLatestByJobPosting($posting));
    }

    public function testFindsNoLetterForAPostingWithout(): void
    {
        $this->save($this->posting('Php'), '2026-10-14 10:00');

        self::assertNull($this->repository()->findLatestByJobPosting($this->posting('Go')));
    }

    private function save(JobPosting $posting, string $createdAt): CoverLetter
    {
        $coverLetter = new CoverLetter($posting, new \DateTimeImmutable($createdAt));
        $this->repository()->save($coverLetter);

        return $coverLetter;
    }

    private function posting(string $title): JobPosting
    {
        $platforms = self::getContainer()->get(PlatformRepository::class);
        self::assertInstanceOf(PlatformRepository::class, $platforms);
        $platform = $platforms->findByName('Upwork');
        self::assertInstanceOf(Platform::class, $platform);

        $posting = new JobPosting(
            $platform,
            JobPostingDetails::create($title, 'Description.', 'https://example.com/'.$title),
            new \DateTimeImmutable('2026-10-14 09:00'),
        );

        $postings = self::getContainer()->get(JobPostingRepository::class);
        self::assertInstanceOf(JobPostingRepository::class, $postings);
        $postings->save($posting);

        return $posting;
    }

    private function repository(): CoverLetterRepository
    {
        $repository = self::getContainer()->get(CoverLetterRepository::class);
        self::assertInstanceOf(CoverLetterRepository::class, $repository);

        return $repository;
    }
}
