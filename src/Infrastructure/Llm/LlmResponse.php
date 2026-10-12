<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

final readonly class LlmResponse
{
    /**
     * @param non-empty-string $text
     */
    public function __construct(
        public string $text,
    ) {
    }
}
