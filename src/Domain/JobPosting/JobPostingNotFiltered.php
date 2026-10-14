<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final class JobPostingNotFiltered extends \DomainException
{
    public static function withStatus(JobPostingStatus $status): self
    {
        return new self(\sprintf(
            'The job posting is not waiting for the LLM evaluation: its status is "%s".',
            $status->value,
        ));
    }
}
