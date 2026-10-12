<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cv;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\InvalidCvAnalysis;
use App\Domain\Cv\LanguageLevel;
use App\Domain\Cv\LanguageProficiency;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CvAnalysisTest extends TestCase
{
    public function testOnlyTheTextsAreRequired(): void
    {
        $analysis = new CvAnalysis('Анализ', 'Analysis', null, null, null);

        self::assertSame('Анализ', $analysis->originalLanguageText);
        self::assertSame('Analysis', $analysis->englishText);
    }

    #[TestWith(['', 'Analysis'])]
    #[TestWith(['Анализ', " \n"])]
    public function testEmptyTextIsRejected(string $original, string $english): void
    {
        $this->expectException(InvalidCvAnalysis::class);

        new CvAnalysis($original, $english, null, null, null);
    }

    #[TestWith([0])]
    #[TestWith([CvAnalysis::MAX_YEARS_EXPERIENCE])]
    public function testYearsExperienceBoundsAreAccepted(int $years): void
    {
        self::assertSame($years, new CvAnalysis('Анализ', 'Analysis', $years, null, null)->yearsExperience);
    }

    #[TestWith([-1])]
    #[TestWith([CvAnalysis::MAX_YEARS_EXPERIENCE + 1])]
    public function testYearsExperienceOutOfRangeIsRejected(int $years): void
    {
        $this->expectException(InvalidCvAnalysis::class);

        new CvAnalysis('Анализ', 'Analysis', $years, null, null);
    }

    public function testDuplicateLanguageIsRejected(): void
    {
        $this->expectException(InvalidCvAnalysis::class);

        new CvAnalysis('Анализ', 'Analysis', null, null, null, [
            LanguageProficiency::create('en', LanguageLevel::C1),
            LanguageProficiency::create('EN', LanguageLevel::B2),
        ]);
    }
}
