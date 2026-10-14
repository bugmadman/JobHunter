<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

/**
 * A null criterion does not narrow the list.
 */
final readonly class JobPostingListFilter
{
    public function __construct(
        public ?int $platformId = null,
        public ?JobPostingStatus $status = null,
        public ?JobPostingVerdict $verdict = null,
    ) {
    }
}
