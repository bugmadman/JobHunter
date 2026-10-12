<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final readonly class LlmModel
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $model): self
    {
        $model = trim($model);

        if ('' === $model) {
            throw EmptyLlmModel::create();
        }

        return new self($model);
    }
}
