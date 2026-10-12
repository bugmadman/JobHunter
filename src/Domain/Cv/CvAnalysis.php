<?php

declare(strict_types=1);

namespace App\Domain\Cv;

final readonly class CvAnalysis
{
    public const int MAX_YEARS_EXPERIENCE = 70;

    /**
     * @param list<LanguageProficiency> $languages
     */
    public function __construct(
        public string $originalLanguageText,
        public string $englishText,
        public ?int $yearsExperience,
        public ?Seniority $seniority,
        public ?SalaryExpectation $salaryExpectation,
        public array $languages = [],
    ) {
        if ('' === trim($originalLanguageText) || '' === trim($englishText)) {
            throw InvalidCvAnalysis::emptyText();
        }

        if (null !== $yearsExperience && ($yearsExperience < 0 || $yearsExperience > self::MAX_YEARS_EXPERIENCE)) {
            throw InvalidCvAnalysis::yearsExperienceOutOfRange($yearsExperience);
        }

        $seen = [];
        foreach ($languages as $proficiency) {
            if (isset($seen[$proficiency->language])) {
                throw InvalidCvAnalysis::duplicateLanguage($proficiency->language);
            }

            $seen[$proficiency->language] = true;
        }
    }
}
