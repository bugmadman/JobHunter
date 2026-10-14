<?php

declare(strict_types=1);

namespace App\Domain\CoverLetter;

final class CoverLetterGenerationInProgress extends \DomainException
{
    public static function forJobPosting(string $title): self
    {
        return new self(\sprintf('A cover letter for the job posting "%s" is being generated.', $title));
    }
}
