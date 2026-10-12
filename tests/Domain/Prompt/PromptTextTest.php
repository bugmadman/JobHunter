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
    public function testRejectsAnEmptyText(string $text): void
    {
        $this->expectException(EmptyPromptText::class);

        PromptText::fromString($text);
    }
}
