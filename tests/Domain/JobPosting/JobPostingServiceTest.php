<?php

declare(strict_types=1);

namespace App\Tests\Domain\JobPosting;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingService;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingStatusChanged;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Repository\JobPostingRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class JobPostingServiceTest extends TestCase
{
    public function testSavesANewRawPostingReceivedNow(): void
    {
        $platform = new Platform('LinkedIn', PlatformAccessType::Browser);
        $details = JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.linkedin.com/jobs/view/1/');
        $now = new \DateTimeImmutable('2026-10-12 09:30:00');
        $postings = $this->createMock(JobPostingRepositoryInterface::class);
        $saved = null;
        $postings->expects($this->once())->method('save')->willReturnCallback(
            static function (JobPosting $posting) use (&$saved): void {
                $saved = $posting;
            },
        );

        $posting = new JobPostingService($postings, new MockClock($now))->create($platform, $details);

        self::assertSame($saved, $posting);
        self::assertSame($platform, $posting->getPlatform());
        self::assertSame('PHP Developer', $posting->getTitle());
        self::assertSame('https://www.linkedin.com/jobs/view/1/', $posting->getUrl());
        self::assertSame(JobPostingStatus::Raw, $posting->getStatus());
        self::assertEquals($now, $posting->getCreatedAt());
        self::assertEquals(
            [new JobPostingStatusChanged(null, JobPostingStatus::Raw, null, $now)],
            $posting->releaseEvents(),
        );
    }
}
