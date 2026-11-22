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
    public function testOnlyTheTextIsRequired(): void
    {
        $analysis = new CvAnalysis('Analysis', null, null, null);

        self::assertSame('Analysis', $analysis->analysis);
    }

    #[TestWith([''])]
    #[TestWith([" \n"])]
    public function testEmptyTextIsRejected(string $text): void
    {
        $this->expectException(InvalidCvAnalysis::class);

        new CvAnalysis($text, null, null, null);
    }

    #[TestWith([0])]
    #[TestWith([CvAnalysis::MAX_YEARS_EXPERIENCE])]
    public function testYearsExperienceBoundsAreAccepted(int $years): void
    {
        self::assertSame($years, new CvAnalysis('Analysis', $years, null, null)->yearsExperience);
    }

    #[TestWith([-1])]
    #[TestWith([CvAnalysis::MAX_YEARS_EXPERIENCE + 1])]
    public function testYearsExperienceOutOfRangeIsRejected(int $years): void
    {
        $this->expectException(InvalidCvAnalysis::class);

        new CvAnalysis('Analysis', $years, null, null);
    }

    public function testDuplicateLanguageIsRejected(): void
    {
        $this->expectException(InvalidCvAnalysis::class);

        new CvAnalysis('Analysis', null, null, null, [
            LanguageProficiency::create('en', LanguageLevel::C1),
            LanguageProficiency::create('EN', LanguageLevel::B2),
        ]);
    }
}
