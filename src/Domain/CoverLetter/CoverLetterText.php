<?php

declare(strict_types=1);

namespace App\Domain\CoverLetter;

use App\Domain\Shared\Text;

final readonly class CoverLetterText
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
            throw EmptyCoverLetterText::create();
        }

        return new self($text);
    }
}
