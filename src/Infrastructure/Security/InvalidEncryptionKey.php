<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class InvalidEncryptionKey extends \InvalidArgumentException
{
    public static function notSet(): self
    {
        return new self('LLM_KEY_ENCRYPTION_KEY is not set: run "make encryption-key" to generate it into .env.local.');
    }

    public static function malformed(): self
    {
        return new self(
            'LLM_KEY_ENCRYPTION_KEY must be the base64 of 32 random bytes:'
            .' remove it from .env.local and run "make encryption-key".',
        );
    }
}
