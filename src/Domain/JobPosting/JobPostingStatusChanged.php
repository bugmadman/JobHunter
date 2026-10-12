<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

/**
 * Recorded by the posting itself and released with JobPosting::releaseEvents(), so it names no posting.
 * `from` is null for the arrival.
 */
final readonly class JobPostingStatusChanged
{
    public function __construct(
        public ?JobPostingStatus $from,
        public JobPostingStatus $to,
        public ?StatusReason $reason,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
