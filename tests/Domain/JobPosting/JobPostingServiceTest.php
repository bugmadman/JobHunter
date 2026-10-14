<?php

declare(strict_types=1);

namespace App\Tests\Domain\JobPosting;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Domain\JobPosting\JobPostingAlreadyFiltered;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingService;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingStatusChanged;
use App\Domain\JobPosting\KeywordFilterInterface;
use App\Domain\JobPosting\StatusReason;
use App\Domain\Platform\PlatformAccessType;
use App\Domain\Skill\SkillName;
use App\Entity\CvProfile;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Entity\Skill;
use App\Repository\CvProfileRepositoryInterface;
use App\Repository\JobPostingRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class JobPostingServiceTest extends TestCase
{
    private const string RECEIVED_AT = '2026-10-12 09:30:00';
    private const string NOW = '2026-10-12 09:31:00';

    public function testSavesANewRawPostingReceivedNow(): void
    {
        $platform = self::platform();
        $details = self::details();
        $now = new \DateTimeImmutable(self::RECEIVED_AT);
        $postings = $this->createMock(JobPostingRepositoryInterface::class);
        $saved = null;
        $postings->expects($this->once())->method('save')->willReturnCallback(
            static function (JobPosting $posting) use (&$saved): void {
                $saved = $posting;
            },
        );

        $posting = self::service($postings, clock: new MockClock($now))->create($platform, $details);

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

    public function testAPostingNamingACvSkillIsFiltered(): void
    {
        $posting = self::rawPosting();
        $php = SkillName::fromString('php');
        $keywordFilter = $this->createMock(KeywordFilterInterface::class);
        $keywordFilter->expects(self::once())
            ->method('matchingSkills')
            ->with(self::details(), [SkillName::fromString('symfony'), $php])
            ->willReturn([$php]);

        self::service(
            self::repositorySaving($posting),
            self::cvProfilesFinding(self::analysedCv('Symfony', 'PHP')),
            $keywordFilter,
        )->filter($posting);

        self::assertSame(JobPostingStatus::Filtered, $posting->getStatus());
        self::assertNull($posting->getStatusReason());
        self::assertEquals(
            [new JobPostingStatusChanged(JobPostingStatus::Raw, JobPostingStatus::Filtered, null, self::now())],
            $posting->releaseEvents(),
        );
    }

    public function testAPostingNamingNoCvSkillIsRejected(): void
    {
        $posting = self::rawPosting();
        $keywordFilter = self::createStub(KeywordFilterInterface::class);
        $keywordFilter->method('matchingSkills')->willReturn([]);

        self::service(
            self::repositorySaving($posting),
            self::cvProfilesFinding(self::analysedCv('Go')),
            $keywordFilter,
        )->filter($posting);

        self::assertFinalStatus(
            $posting,
            JobPostingStatus::Rejected,
            'None of the CV skills appear in the posting',
        );
    }

    public function testWithoutAnAnalysedCvThePostingFails(): void
    {
        $posting = self::rawPosting();

        self::service(
            self::repositorySaving($posting),
            self::cvProfilesFinding(null),
            self::keywordFilterNeverCalled(),
        )->filter($posting);

        self::assertFinalStatus($posting, JobPostingStatus::Failed, 'No analysed CV to take the skills from');
    }

    public function testWithACvWithoutSkillsThePostingFails(): void
    {
        $posting = self::rawPosting();

        self::service(
            self::repositorySaving($posting),
            self::cvProfilesFinding(self::analysedCv()),
            self::keywordFilterNeverCalled(),
        )->filter($posting);

        self::assertFinalStatus($posting, JobPostingStatus::Failed, 'The analysed CV has no skills');
    }

    public function testAPostingThatIsNoLongerRawIsLeftAlone(): void
    {
        $posting = self::rawPosting();
        $posting->markFiltered(self::now());
        $posting->releaseEvents();

        $postings = $this->createMock(JobPostingRepositoryInterface::class);
        $postings->expects(self::never())->method('save');
        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::never())->method('findLatestAnalysed');

        try {
            self::service($postings, $cvProfiles, self::keywordFilterNeverCalled())->filter($posting);
            self::fail('Expected a posting that is no longer raw to be refused.');
        } catch (JobPostingAlreadyFiltered $exception) {
            self::assertSame(
                'The job posting has already left the keyword filter with the status "filtered".',
                $exception->getMessage(),
            );
        }

        self::assertSame(JobPostingStatus::Filtered, $posting->getStatus());
        self::assertSame([], $posting->releaseEvents());
    }

    private static function assertFinalStatus(JobPosting $posting, JobPostingStatus $status, string $reason): void
    {
        self::assertSame($status, $posting->getStatus());
        self::assertSame($reason, $posting->getStatusReason());
        self::assertEquals(
            [
                new JobPostingStatusChanged(
                    JobPostingStatus::Raw,
                    $status,
                    StatusReason::fromString($reason),
                    self::now(),
                ),
            ],
            $posting->releaseEvents(),
        );
    }

    private function repositorySaving(JobPosting $posting): JobPostingRepositoryInterface
    {
        $postings = $this->createMock(JobPostingRepositoryInterface::class);
        $postings->expects(self::once())->method('save')->with($posting);

        return $postings;
    }

    private function cvProfilesFinding(?CvProfile $cvProfile): CvProfileRepositoryInterface
    {
        $cvProfiles = $this->createMock(CvProfileRepositoryInterface::class);
        $cvProfiles->expects(self::once())->method('findLatestAnalysed')->willReturn($cvProfile);

        return $cvProfiles;
    }

    private function keywordFilterNeverCalled(): KeywordFilterInterface
    {
        $keywordFilter = $this->createMock(KeywordFilterInterface::class);
        $keywordFilter->expects(self::never())->method('matchingSkills');

        return $keywordFilter;
    }

    private static function service(
        ?JobPostingRepositoryInterface $postings = null,
        ?CvProfileRepositoryInterface $cvProfiles = null,
        ?KeywordFilterInterface $keywordFilter = null,
        ?MockClock $clock = null,
    ): JobPostingService {
        return new JobPostingService(
            $postings ?? self::createStub(JobPostingRepositoryInterface::class),
            $cvProfiles ?? self::createStub(CvProfileRepositoryInterface::class),
            $keywordFilter ?? self::createStub(KeywordFilterInterface::class),
            $clock ?? new MockClock(self::now()),
        );
    }

    private static function rawPosting(): JobPosting
    {
        $posting = new JobPosting(self::platform(), self::details(), new \DateTimeImmutable(self::RECEIVED_AT));
        $posting->releaseEvents();

        return $posting;
    }

    private static function analysedCv(string ...$skills): CvProfile
    {
        $cvProfile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $cvProfile->markAnalysisDone(
            new CvAnalysis('Анализ', 'Analysis', null, null, null),
            array_map(
                static fn (string $skill): Skill => new Skill(SkillName::fromString($skill)),
                array_values($skills),
            ),
            '{}',
        );

        return $cvProfile;
    }

    private static function platform(): Platform
    {
        return new Platform('LinkedIn', PlatformAccessType::Browser);
    }

    private static function details(): JobPostingDetails
    {
        return JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.linkedin.com/jobs/view/1/');
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
