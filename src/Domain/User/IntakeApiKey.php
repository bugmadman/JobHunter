<?php

declare(strict_types=1);

namespace App\Domain\User;

final readonly class IntakeApiKey
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(
        #[\SensitiveParameter]
        private string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(32)));
    }

    /**
     * @return non-empty-string
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Unsalted SHA-256 is enough for 256 random bits, and being deterministic it lets the key be looked up by its hash.
     *
     * @return non-empty-string
     */
    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
