<?php

declare(strict_types=1);

namespace App\Domain\CoverLetter;

final class EmptyCoverLetterText extends \DomainException
{
    public static function create(): self
    {
        return new self('The cover letter text must not be empty.');
    }
}
