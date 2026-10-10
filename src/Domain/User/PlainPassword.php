<?php

declare(strict_types=1);

namespace App\Domain\User;

final readonly class PlainPassword
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(
        #[\SensitiveParameter]
        public string $value,
    ) {
    }

    public static function fromString(#[\SensitiveParameter] string $password): self
    {
        if ('' === trim($password)) {
            throw EmptyPassword::create();
        }

        return new self($password);
    }
}
