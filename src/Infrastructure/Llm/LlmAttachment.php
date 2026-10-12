<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

final readonly class LlmAttachment
{
    /**
     * @param non-empty-string $contents
     */
    private function __construct(
        public LlmAttachmentType $type,
        public string $contents,
    ) {
    }

    public static function fromContents(LlmAttachmentType $type, string $contents): self
    {
        if ('' === $contents) {
            throw EmptyLlmAttachment::create();
        }

        return new self($type, $contents);
    }
}
