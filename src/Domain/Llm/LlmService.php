<?php

declare(strict_types=1);

namespace App\Domain\Llm;

use App\Entity\LlmModelSelection;
use App\Entity\LlmProviderKey;
use App\Infrastructure\Security\ApiKeyEncryptorInterface;
use App\Repository\LlmModelSelectionRepositoryInterface;
use App\Repository\LlmProviderKeyRepositoryInterface;

final readonly class LlmService implements LlmServiceInterface
{
    public function __construct(
        private LlmProviderKeyRepositoryInterface $providerKeys,
        private ApiKeyEncryptorInterface $apiKeyEncryptor,
        private LlmModelSelectionRepositoryInterface $modelSelections,
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

    public function selectModel(LlmTask $task, LlmProvider $provider, LlmModel $model): void
    {
        $selection = $this->modelSelections->findByTask($task);

        if (null === $selection) {
            $selection = new LlmModelSelection($task, $provider, $model);
        } else {
            $selection->changeModel($provider, $model);
        }

        $this->modelSelections->save($selection);
    }

    public function deleteModelSelection(LlmTask $task): void
    {
        $selection = $this->modelSelections->findByTask($task);

        if (null !== $selection) {
            $this->modelSelections->remove($selection);
        }
    }
}
