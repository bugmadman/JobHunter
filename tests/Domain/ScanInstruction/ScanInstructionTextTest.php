<?php

declare(strict_types=1);

namespace App\Tests\Domain\ScanInstruction;

use App\Domain\ScanInstruction\EmptyScanInstructionText;
use App\Domain\ScanInstruction\ScanInstructionText;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ScanInstructionTextTest extends TestCase
{
    public function testTrimsSurroundingWhitespaceOnly(): void
    {
        self::assertSame(
            "Search \"Symfony\".\n\nSkip WordPress.",
            ScanInstructionText::fromString("\n  Search \"Symfony\".\n\nSkip WordPress. \n")->value,
        );
    }

    #[TestWith([''])]
    #[TestWith(["  \t\n"])]
    #[TestWith(["\u{00A0}\u{200B}"])]
    public function testRejectsAnEmptyText(string $text): void
    {
        $this->expectException(EmptyScanInstructionText::class);

        ScanInstructionText::fromString($text);
    }
}
