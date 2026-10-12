<?php

declare(strict_types=1);

namespace App\Domain\JobPosting;

final class InvalidJobPostingDetails extends \DomainException
{
    public static function emptyField(string $field): self
    {
        return new self(\sprintf('The job posting %s must not be empty.', $field));
    }

    public static function tooLong(string $field, int $maxLength): self
    {
        return new self(\sprintf('The job posting %s must not exceed %d characters.', $field, $maxLength));
    }

    public static function invalidText(string $field): self
    {
        return new self(\sprintf('The job posting %s must be valid UTF-8 without NUL bytes.', $field));
    }

    public static function invalidUrl(string $url): self
    {
        return new self(\sprintf('"%s" is not an absolute http(s) URL.', $url));
    }
}
