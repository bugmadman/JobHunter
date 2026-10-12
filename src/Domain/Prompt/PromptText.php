<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

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
        $text = trim($text);

        if ('' === $text) {
            throw EmptyPromptText::create();
        }

        return new self($text);
    }
}
