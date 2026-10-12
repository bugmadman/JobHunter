<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class ApiKeyDecryptionFailed extends \RuntimeException
{
    public static function create(): self
    {
        return new self(
            'The API key cannot be decrypted: the stored value is corrupted'
            .' or was encrypted with another LLM_KEY_ENCRYPTION_KEY.',
        );
    }
}
