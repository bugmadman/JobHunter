<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

use App\Domain\Shared\Text;

final readonly class PromptText
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
            throw EmptyPromptText::create();
        }

        return new self($text);
    }
}
