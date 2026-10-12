<?php

declare(strict_types=1);

namespace App\Domain\Llm;

use App\Entity\LlmProviderKey;
use App\Infrastructure\Security\ApiKeyEncryptorInterface;
use App\Repository\LlmProviderKeyRepositoryInterface;

final readonly class LlmService implements LlmServiceInterface
{
    public function __construct(
        private LlmProviderKeyRepositoryInterface $providerKeys,
        private ApiKeyEncryptorInterface $apiKeyEncryptor,
    ) {
    }

    public function saveApiKey(LlmProvider $provider, ApiKey $apiKey): void
    {
        $encryptedApiKey = $this->apiKeyEncryptor->encrypt($apiKey);
        $providerKey = $this->providerKeys->findByProvider($provider);

        if (null === $providerKey) {
            $providerKey = new LlmProviderKey($provider, $encryptedApiKey);
        } else {
            $providerKey->changeApiKey($encryptedApiKey);
        }

        $this->providerKeys->save($providerKey);
    }

    public function findApiKey(LlmProvider $provider): ?ApiKey
    {
        $providerKey = $this->providerKeys->findByProvider($provider);

        return null === $providerKey ? null : $this->apiKeyEncryptor->decrypt($providerKey->getEncryptedApiKey());
    }

    public function deleteApiKey(LlmProvider $provider): void
    {
        $providerKey = $this->providerKeys->findByProvider($provider);

        if (null !== $providerKey) {
            $this->providerKeys->remove($providerKey);
        }
    }
}
