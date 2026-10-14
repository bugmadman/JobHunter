<?php

declare(strict_types=1);

namespace App\Tests\Domain\CoverLetter;

use App\Domain\CoverLetter\CoverLetterText;
use App\Domain\CoverLetter\EmptyCoverLetterText;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CoverLetterTextTest extends TestCase
{
    public function testTrimsSurroundingWhitespaceOnly(): void
    {
        self::assertSame(
            "Hello,\n\nI fit.",
            CoverLetterText::fromString("\n  Hello,\n\nI fit. \n")->value,
        );
    }

    #[TestWith([''])]
    #[TestWith(["  \t\n"])]
    #[TestWith(["\u{00A0}\u{200B}"])]
    public function testRejectsAnEmptyText(string $text): void
    {
        $this->expectException(EmptyCoverLetterText::class);

        CoverLetterText::fromString($text);
    }
}
