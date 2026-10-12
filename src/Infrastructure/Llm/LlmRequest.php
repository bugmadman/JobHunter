<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

final readonly class LlmRequest
{
    /**
     * @param non-empty-string      $prompt
     * @param non-empty-string|null $systemInstruction
     */
    public function __construct(
        public string $prompt,
        public ?string $systemInstruction = null,
        public ?LlmAttachment $attachment = null,
        public ?JsonSchema $responseSchema = null,
    ) {
    }
}
