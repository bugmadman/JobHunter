<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final class JobPostingNotEvaluated extends \DomainException
{
    public static function withStatus(JobPostingStatus $status): self
    {
        return new self(\sprintf(
            'The job posting has no LLM verdict to override: its status is "%s".',
            $status->value,
        ));
    }
}
