<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final readonly class JobPostingEvaluation
{
    public function __construct(
        public JobPostingVerdict $verdict,
        public StatusReason $reason,
    ) {
    }
}
