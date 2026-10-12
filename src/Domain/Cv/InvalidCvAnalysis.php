<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final class InvalidCvAnalysis extends \DomainException
{
    public static function emptyText(): self
    {
        return new self('The CV analysis text must not be empty.');
    }

    public static function yearsExperienceOutOfRange(int $years): self
    {
        return new self(\sprintf(
            'Years of experience must be between 0 and %d, got %d.',
            CvAnalysis::MAX_YEARS_EXPERIENCE,
            $years,
        ));
    }
}
