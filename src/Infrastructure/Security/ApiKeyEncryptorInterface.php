<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\EncryptedApiKey;

interface ApiKeyEncryptorInterface
{
    public function encrypt(ApiKey $apiKey): EncryptedApiKey;

    /**
     * @throws ApiKeyDecryptionFailed
     */
    public function decrypt(EncryptedApiKey $encryptedApiKey): ApiKey;
}
