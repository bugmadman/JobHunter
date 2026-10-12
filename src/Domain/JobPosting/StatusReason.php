<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

use App\Domain\Shared\Text;

/**
 * Why a job posting reached its final status: the keyword cutoff, the failure, or the reason of the LLM's verdict.
 */
final readonly class StatusReason
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $reason): self
    {
        $reason = Text::trim($reason);

        if ('' === $reason) {
            throw EmptyStatusReason::create();
        }

        return new self($reason);
    }
}
