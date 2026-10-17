<?php

declare(strict_types=1);

namespace App\Domain\ScanInstruction;

use App\Domain\Shared\Text;

final readonly class ScanInstructionText
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $text): self
    {
        $text = Text::trim($text);

        if ('' === $text) {
            throw EmptyScanInstructionText::create();
        }

        return new self($text);
    }
}
