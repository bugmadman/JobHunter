<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final readonly class ApiKey
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(
        #[\SensitiveParameter]
        private string $value,
    ) {
    }

    public static function fromString(#[\SensitiveParameter] string $apiKey): self
    {
        $apiKey = trim($apiKey);

        if ('' === $apiKey) {
            throw EmptyApiKey::create();
        }

        return new self($apiKey);
    }

    /**
     * @return non-empty-string
     */
    public function value(): string
    {
        return $this->value;
    }
}
