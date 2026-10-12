<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final readonly class CvAnalysis
{
    public const int MAX_YEARS_EXPERIENCE = 70;

    public function __construct(
        public string $originalLanguageText,
        public string $englishText,
        public ?int $yearsExperience,
        public ?Seniority $seniority,
        public ?SalaryExpectation $salaryExpectation,
    ) {
        if ('' === trim($originalLanguageText) || '' === trim($englishText)) {
            throw InvalidCvAnalysis::emptyText();
        }

        if (null !== $yearsExperience && ($yearsExperience < 0 || $yearsExperience > self::MAX_YEARS_EXPERIENCE)) {
            throw InvalidCvAnalysis::yearsExperienceOutOfRange($yearsExperience);
        }
    }
}
