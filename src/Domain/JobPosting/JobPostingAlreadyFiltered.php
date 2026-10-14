<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final class JobPostingAlreadyFiltered extends \DomainException
{
    public static function withStatus(JobPostingStatus $status): self
    {
        return new self(\sprintf(
            'The job posting has already left the keyword filter with the status "%s".',
            $status->value,
        ));
    }
}
