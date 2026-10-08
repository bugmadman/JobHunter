<?php

declare(strict_types=1);

namespace App\Domain\Shared;

final readonly class Email
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $email): self
    {
        $normalized = mb_strtolower(trim($email));
        if ('' === $normalized || false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw InvalidEmail::fromValue($email);
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
