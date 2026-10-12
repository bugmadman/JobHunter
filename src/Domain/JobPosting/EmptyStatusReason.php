<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final class EmptyStatusReason extends \DomainException
{
    public static function create(): self
    {
        return new self('The job posting status reason must not be empty.');
    }
}
