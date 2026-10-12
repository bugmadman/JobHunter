<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cv;

use App\Domain\Cv\InvalidLanguageProficiency;
use App\Domain\Cv\LanguageLevel;
use App\Domain\Cv\LanguageProficiency;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class LanguageProficiencyTest extends TestCase
{
    public function testLanguageIsNormalized(): void
    {
        $proficiency = LanguageProficiency::create(' EN ', LanguageLevel::C1);

        self::assertSame('en', $proficiency->language);
        self::assertSame(LanguageLevel::C1, $proficiency->level);
    }

    #[TestWith([''])]
    #[TestWith(['e'])]
    #[TestWith(['eng'])]
    #[TestWith(['English'])]
    #[TestWith(['e1'])]
    public function testInvalidLanguageIsRejected(string $language): void
    {
        $this->expectException(InvalidLanguageProficiency::class);

        LanguageProficiency::create($language, LanguageLevel::B2);
    }
}
