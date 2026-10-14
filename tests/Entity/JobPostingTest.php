<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\JobPosting\InvalidJobPostingTransition;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingNotEvaluated;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingStatusChanged;
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
        self::assertNull($posting->getEffectiveVerdict());
    }

    public function testRawPostingIsRejectedWithTheReason(): void
    {
        $posting = self::postingIn(JobPostingStatus::Raw);

        $posting->markRejected(StatusReason::fromString('No "PHP" in the title.'), self::at());

        self::assertSame(JobPostingStatus::Rejected, $posting->getStatus());
        self::assertSame('No "PHP" in the title.', $posting->getStatusReason());
        self::assertNull($posting->getVerdict());
    }

    public function testRawPostingIsFilteredWithoutReason(): void
    {
        $posting = self::postingIn(JobPostingStatus::Raw);

        $posting->markFiltered(self::at());

        self::assertSame(JobPostingStatus::Filtered, $posting->getStatus());
        self::assertNull($posting->getStatusReason());
        self::assertNull($posting->getVerdict());
    }

    #[TestWith([JobPostingVerdict::Like])]
    #[TestWith([JobPostingVerdict::Dislike])]
    public function testFilteredPostingIsEvaluatedWithTheVerdictAndReason(JobPostingVerdict $verdict): void
    {
        $posting = self::postingIn(JobPostingStatus::Filtered);

        $posting->markEvaluated($verdict, StatusReason::fromString('Symfony and remote, as the CV asks.'), self::at());

        self::assertSame(JobPostingStatus::Evaluated, $posting->getStatus());
        self::assertSame('Symfony and remote, as the CV asks.', $posting->getStatusReason());
        self::assertSame($verdict, $posting->getVerdict());
        self::assertNull($posting->getManualVerdict());
        self::assertSame($verdict, $posting->getEffectiveVerdict());
    }

    #[TestWith([JobPostingStatus::Raw])]
    #[TestWith([JobPostingStatus::Filtered])]
    public function testPostingFailsWithTheReason(JobPostingStatus $from): void
    {
        $posting = self::postingIn($from);

        $posting->markFailed(StatusReason::fromString('The LLM returned invalid JSON.'), self::at());

        self::assertSame(JobPostingStatus::Failed, $posting->getStatus());
        self::assertSame('The LLM returned invalid JSON.', $posting->getStatusReason());
        self::assertNull($posting->getVerdict());
    }

    public function testArrivalAndTransitionsAreReleasedOnceInOrder(): void
    {
        $posting = self::postingIn(JobPostingStatus::Raw);
        $posting->markFiltered(new \DateTimeImmutable('2026-10-12 09:31:00'));
        $posting->markEvaluated(
            JobPostingVerdict::Like,
            StatusReason::fromString('Symfony and remote.'),
            new \DateTimeImmutable('2026-10-12 09:45:00'),
        );

        self::assertEquals(
            [
                new JobPostingStatusChanged(
                    null,
                    JobPostingStatus::Raw,
                    null,
                    new \DateTimeImmutable('2026-10-12 09:30:00'),
                ),
                new JobPostingStatusChanged(
                    JobPostingStatus::Raw,
                    JobPostingStatus::Filtered,
                    null,
                    new \DateTimeImmutable('2026-10-12 09:31:00'),
                ),
                new JobPostingStatusChanged(
                    JobPostingStatus::Filtered,
                    JobPostingStatus::Evaluated,
                    StatusReason::fromString('Symfony and remote.'),
                    new \DateTimeImmutable('2026-10-12 09:45:00'),
                ),
            ],
            $posting->releaseEvents(),
        );
        self::assertSame([], $posting->releaseEvents());
    }

    #[DataProvider('allowedTransitions')]
    public function testTransitionRecordsAnEventWithTheReasonAndTime(
        JobPostingStatus $from,
        string $transition,
        ?string $reason,
    ): void {
        $posting = self::postingIn($from);
        $posting->releaseEvents();
        [$to, $apply] = self::transitions()[$transition];

        $apply($posting);

        self::assertEquals(
            [
                new JobPostingStatusChanged(
                    $from,
                    $to,
                    null === $reason ? null : StatusReason::fromString($reason),
                    self::at(),
                ),
            ],
            $posting->releaseEvents(),
        );
    }

    /**
     * @return iterable<string, array{JobPostingStatus, string, ?string}>
     */
    public static function allowedTransitions(): iterable
    {
        yield 'reject from raw' => [JobPostingStatus::Raw, 'reject', 'Late cutoff.'];
        yield 'filter from raw' => [JobPostingStatus::Raw, 'filter', null];
        yield 'fail from raw' => [JobPostingStatus::Raw, 'fail', 'Late failure.'];
        yield 'evaluate from filtered' => [JobPostingStatus::Filtered, 'evaluate', 'Late verdict.'];
        yield 'fail from filtered' => [JobPostingStatus::Filtered, 'fail', 'Late failure.'];
    }

    #[DataProvider('forbiddenTransitions')]
    public function testForbiddenTransitionThrowsAndLeavesThePostingUnchanged(
        JobPostingStatus $from,
        string $transition,
    ): void {
        $posting = self::postingIn($from);
        $posting->releaseEvents();
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
        self::assertSame([], $posting->releaseEvents());
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

    public function testOwnerOverridesTheLlmVerdictAndTheLlmVerdictIsKept(): void
    {
        $posting = self::postingIn(JobPostingStatus::Evaluated);
        $posting->releaseEvents();

        $posting->overrideVerdict(JobPostingVerdict::Dislike);

        self::assertSame(JobPostingVerdict::Dislike, $posting->getManualVerdict());
        self::assertSame(JobPostingVerdict::Dislike, $posting->getEffectiveVerdict());
        self::assertSame(JobPostingVerdict::Like, $posting->getVerdict());
        self::assertSame(JobPostingStatus::Evaluated, $posting->getStatus());
        self::assertSame('Symfony and remote.', $posting->getStatusReason());
        self::assertSame([], $posting->releaseEvents());
    }

    public function testRepeatingTheOverrideKeepsIt(): void
    {
        $posting = self::postingIn(JobPostingStatus::Evaluated);
        $posting->overrideVerdict(JobPostingVerdict::Dislike);

        $posting->overrideVerdict(JobPostingVerdict::Dislike);

        self::assertSame(JobPostingVerdict::Dislike, $posting->getManualVerdict());
    }

    public function testChoosingTheLlmVerdictDropsTheOverride(): void
    {
        $posting = self::postingIn(JobPostingStatus::Evaluated);
        $posting->overrideVerdict(JobPostingVerdict::Dislike);

        $posting->overrideVerdict(JobPostingVerdict::Like);

        self::assertNull($posting->getManualVerdict());
        self::assertSame(JobPostingVerdict::Like, $posting->getEffectiveVerdict());
    }

    public function testAgreeingWithTheLlmStoresNoOverride(): void
    {
        $posting = self::postingIn(JobPostingStatus::Evaluated);

        $posting->overrideVerdict(JobPostingVerdict::Like);

        self::assertNull($posting->getManualVerdict());
        self::assertSame(JobPostingVerdict::Like, $posting->getEffectiveVerdict());
    }

    #[TestWith([JobPostingStatus::Raw, JobPostingVerdict::Like])]
    #[TestWith([JobPostingStatus::Rejected, JobPostingVerdict::Like])]
    #[TestWith([JobPostingStatus::Filtered, JobPostingVerdict::Dislike])]
    #[TestWith([JobPostingStatus::Failed, JobPostingVerdict::Dislike])]
    public function testAPostingWithoutAnLlmVerdictCannotBeOverridden(
        JobPostingStatus $status,
        JobPostingVerdict $verdict,
    ): void {
        $posting = self::postingIn($status);

        try {
            $posting->overrideVerdict($verdict);
            self::fail(\sprintf('A "%s" posting was overridden.', $status->value));
        } catch (JobPostingNotEvaluated $e) {
            self::assertSame(
                \sprintf('The job posting has no LLM verdict to override: its status is "%s".', $status->value),
                $e->getMessage(),
            );
        }

        self::assertNull($posting->getManualVerdict());
        self::assertNull($posting->getEffectiveVerdict());
    }

    public function testAppliedIsDatedAndKeepsTheFirstDate(): void
    {
        $posting = self::postingIn(JobPostingStatus::Rejected);
        $posting->releaseEvents();
        self::assertFalse($posting->isApplied());

        $posting->markApplied(self::at());
        $posting->markApplied(new \DateTimeImmutable('2026-10-13 10:00:00'));

        self::assertTrue($posting->isApplied());
        self::assertEquals(self::at(), $posting->getAppliedAt());
        self::assertSame(JobPostingStatus::Rejected, $posting->getStatus());
        self::assertSame([], $posting->releaseEvents());
    }

    public function testNotAppliedClearsTheDate(): void
    {
        $posting = self::postingIn(JobPostingStatus::Raw);
        $posting->markApplied(self::at());

        $posting->markNotApplied();

        self::assertFalse($posting->isApplied());
        self::assertNull($posting->getAppliedAt());
    }

    /**
     * @return array<string, array{JobPostingStatus, \Closure(JobPosting): void}>
     */
    private static function transitions(): array
    {
        return [
            'reject' => [
                JobPostingStatus::Rejected,
                static fn (JobPosting $posting) => $posting->markRejected(
                    StatusReason::fromString('Late cutoff.'),
                    self::at(),
                ),
            ],
            'filter' => [
                JobPostingStatus::Filtered,
                static fn (JobPosting $posting) => $posting->markFiltered(self::at()),
            ],
            'evaluate' => [
                JobPostingStatus::Evaluated,
                static fn (JobPosting $posting) => $posting->markEvaluated(
                    JobPostingVerdict::Dislike,
                    StatusReason::fromString('Late verdict.'),
                    self::at(),
                ),
            ],
            'fail' => [
                JobPostingStatus::Failed,
                static fn (JobPosting $posting) => $posting->markFailed(
                    StatusReason::fromString('Late failure.'),
                    self::at(),
                ),
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
            JobPostingStatus::Rejected => $posting->markRejected(
                StatusReason::fromString('No "PHP" in the title.'),
                self::at(),
            ),
            JobPostingStatus::Filtered => $posting->markFiltered(self::at()),
            JobPostingStatus::Evaluated => self::evaluate($posting),
            JobPostingStatus::Failed => $posting->markFailed(
                StatusReason::fromString('The page did not load.'),
                self::at(),
            ),
        };

        return $posting;
    }

    private static function evaluate(JobPosting $posting): void
    {
        $posting->markFiltered(self::at());
        $posting->markEvaluated(JobPostingVerdict::Like, StatusReason::fromString('Symfony and remote.'), self::at());
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-12 10:00:00');
    }
}
