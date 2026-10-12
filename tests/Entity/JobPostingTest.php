<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\JobPosting\InvalidJobPostingTransition;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Domain\JobPosting\StatusReason;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\JobPosting;
use App\Entity\Platform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class JobPostingTest extends TestCase
{
    /**
     * Allowed transitions by source status; every other combination must throw.
     */
    private const array ALLOWED = [
        'raw' => ['reject', 'filter', 'fail'],
        'filtered' => ['evaluate', 'fail'],
    ];

    public function testNewPostingIsRawWithoutReasonAndVerdict(): void
    {
        $posting = self::postingIn(JobPostingStatus::Raw);

        self::assertSame(JobPostingStatus::Raw, $posting->getStatus());
        self::assertNull($posting->getStatusReason());
        self::assertNull($posting->getVerdict());
    }

    public function testRawPostingIsRejectedWithTheReason(): void
    {
        $posting = self::postingIn(JobPostingStatus::Raw);

        $posting->markRejected(StatusReason::fromString('No "PHP" in the title.'));

        self::assertSame(JobPostingStatus::Rejected, $posting->getStatus());
        self::assertSame('No "PHP" in the title.', $posting->getStatusReason());
        self::assertNull($posting->getVerdict());
    }

    public function testRawPostingIsFilteredWithoutReason(): void
    {
        $posting = self::postingIn(JobPostingStatus::Raw);

        $posting->markFiltered();

        self::assertSame(JobPostingStatus::Filtered, $posting->getStatus());
        self::assertNull($posting->getStatusReason());
        self::assertNull($posting->getVerdict());
    }

    #[TestWith([JobPostingVerdict::Like])]
    #[TestWith([JobPostingVerdict::Dislike])]
    public function testFilteredPostingIsEvaluatedWithTheVerdictAndReason(JobPostingVerdict $verdict): void
    {
        $posting = self::postingIn(JobPostingStatus::Filtered);

        $posting->markEvaluated($verdict, StatusReason::fromString('Symfony and remote, as the CV asks.'));

        self::assertSame(JobPostingStatus::Evaluated, $posting->getStatus());
        self::assertSame('Symfony and remote, as the CV asks.', $posting->getStatusReason());
        self::assertSame($verdict, $posting->getVerdict());
    }

    #[TestWith([JobPostingStatus::Raw])]
    #[TestWith([JobPostingStatus::Filtered])]
    public function testPostingFailsWithTheReason(JobPostingStatus $from): void
    {
        $posting = self::postingIn($from);

        $posting->markFailed(StatusReason::fromString('The LLM returned invalid JSON.'));

        self::assertSame(JobPostingStatus::Failed, $posting->getStatus());
        self::assertSame('The LLM returned invalid JSON.', $posting->getStatusReason());
        self::assertNull($posting->getVerdict());
    }

    #[DataProvider('forbiddenTransitions')]
    public function testForbiddenTransitionThrowsAndLeavesThePostingUnchanged(
        JobPostingStatus $from,
        string $transition,
    ): void {
        $posting = self::postingIn($from);
        $reason = $posting->getStatusReason();
        $verdict = $posting->getVerdict();
        [$to, $apply] = self::transitions()[$transition];

        try {
            $apply($posting);
            self::fail(\sprintf('"%s" was allowed from "%s".', $transition, $from->value));
        } catch (InvalidJobPostingTransition $e) {
            self::assertSame(
                \sprintf('A job posting can\'t go from "%s" to "%s".', $from->value, $to->value),
                $e->getMessage(),
            );
        }

        self::assertSame($from, $posting->getStatus());
        self::assertSame($reason, $posting->getStatusReason());
        self::assertSame($verdict, $posting->getVerdict());
    }

    /**
     * @return iterable<string, array{JobPostingStatus, string}>
     */
    public static function forbiddenTransitions(): iterable
    {
        foreach (JobPostingStatus::cases() as $from) {
            foreach (array_keys(self::transitions()) as $transition) {
                if (!\in_array($transition, self::ALLOWED[$from->value] ?? [], true)) {
                    yield \sprintf('%s from %s', $transition, $from->value) => [$from, $transition];
                }
            }
        }
    }

    /**
     * @return array<string, array{JobPostingStatus, \Closure(JobPosting): void}>
     */
    private static function transitions(): array
    {
        return [
            'reject' => [
                JobPostingStatus::Rejected,
                static fn (JobPosting $posting) => $posting->markRejected(StatusReason::fromString('Late cutoff.')),
            ],
            'filter' => [
                JobPostingStatus::Filtered,
                static fn (JobPosting $posting) => $posting->markFiltered(),
            ],
            'evaluate' => [
                JobPostingStatus::Evaluated,
                static fn (JobPosting $posting) => $posting->markEvaluated(
                    JobPostingVerdict::Dislike,
                    StatusReason::fromString('Late verdict.'),
                ),
            ],
            'fail' => [
                JobPostingStatus::Failed,
                static fn (JobPosting $posting) => $posting->markFailed(StatusReason::fromString('Late failure.')),
            ],
        ];
    }

    private static function postingIn(JobPostingStatus $status): JobPosting
    {
        $posting = new JobPosting(
            new Platform('LinkedIn', PlatformAccessType::Browser),
            JobPostingDetails::create('PHP Developer', 'Symfony.', 'https://www.linkedin.com/jobs/view/456/'),
            new \DateTimeImmutable('2026-10-12 09:30:00'),
        );

        match ($status) {
            JobPostingStatus::Raw => null,
            JobPostingStatus::Rejected => $posting->markRejected(StatusReason::fromString('No "PHP" in the title.')),
            JobPostingStatus::Filtered => $posting->markFiltered(),
            JobPostingStatus::Evaluated => self::evaluate($posting),
            JobPostingStatus::Failed => $posting->markFailed(StatusReason::fromString('The page did not load.')),
        };

        return $posting;
    }

    private static function evaluate(JobPosting $posting): void
    {
        $posting->markFiltered();
        $posting->markEvaluated(JobPostingVerdict::Like, StatusReason::fromString('Symfony and remote.'));
    }
}
