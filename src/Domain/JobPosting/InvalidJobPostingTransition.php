<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final class InvalidJobPostingTransition extends \DomainException
{
    public static function create(JobPostingStatus $from, JobPostingStatus $to): self
    {
        return new self(\sprintf('A job posting can\'t go from "%s" to "%s".', $from->value, $to->value));
    }
}
