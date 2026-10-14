<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final class CommentTooLong extends \DomainException
{
    public static function create(int $length): self
    {
        return new self(\sprintf(
            'A job posting comment must be at most %d characters long, got %d.',
            JobPostingComment::MAX_LENGTH,
            $length,
        ));
    }
}
