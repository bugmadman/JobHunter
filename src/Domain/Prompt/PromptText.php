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
        // trim() misses Unicode spaces and invisible characters, so a lone NBSP would pass as a prompt
        $text = (string) preg_replace('/^[\pZ\p{Cc}\p{Cf}]+|[\pZ\p{Cc}\p{Cf}]+$/u', '', $text);

        if ('' === $text) {
            throw EmptyPromptText::create();
        }

        return new self($text);
    }
}
