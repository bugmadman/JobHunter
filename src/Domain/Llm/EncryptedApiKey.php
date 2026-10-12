<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final readonly class EncryptedApiKey
{
    public function __construct(
        private string $value,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }
}
