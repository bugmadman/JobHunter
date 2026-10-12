<?php

declare(strict_types=1);

namespace App\Tests\Domain\Prompt;

use App\Domain\Prompt\EmptyPromptText;
use App\Domain\Prompt\PromptText;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PromptTextTest extends TestCase
{
    public function testTrimsSurroundingWhitespaceOnly(): void
    {
        self::assertSame(
            "Match PHP jobs.\n\nSkip WordPress.",
            PromptText::fromString("\n  Match PHP jobs.\n\nSkip WordPress. \n")->value,
        );
    }

    #[TestWith([''])]
    #[TestWith(["  \t\n"])]
    #[TestWith(["\u{00A0}\u{200B}"])]
    public function testRejectsAnEmptyText(string $text): void
    {
        $this->expectException(EmptyPromptText::class);

        PromptText::fromString($text);
    }
}
