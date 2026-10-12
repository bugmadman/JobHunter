<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessJobPosting
{
    public function __construct(
        public int $jobPostingId,
    ) {
    }
}
