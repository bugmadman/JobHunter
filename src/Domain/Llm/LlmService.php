<?php

declare(strict_types=1);

namespace App\Domain\Llm;

use App\Entity\LlmModelSelection;
use App\Entity\LlmProviderKey;
use App\Infrastructure\Llm\LlmClientInterface;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmResponse;
use App\Infrastructure\Security\ApiKeyEncryptorInterface;
use App\Repository\LlmModelSelectionRepositoryInterface;
use App\Repository\LlmProviderKeyRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class LlmService implements LlmServiceInterface
{
    /**
     * @param iterable<string, LlmClientInterface> $clients indexed by the provider value
     */
    public function __construct(
        private LlmProviderKeyRepositoryInterface $providerKeys,
        private ApiKeyEncryptorInterface $apiKeyEncryptor,
        private LlmModelSelectionRepositoryInterface $modelSelections,
        // Without an indexAttribute the iterator is a list and the #[AsTaggedItem] index of each client is dropped.
        #[AutowireIterator(LlmClientInterface::class, indexAttribute: 'provider')]
        private iterable $clients,
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

    public function generate(LlmTask $task, LlmRequest $request): LlmResponse
    {
        $selection = $this->modelSelections->findByTask($task) ?? throw LlmModelNotSelected::forTask($task);
        $provider = $selection->getProvider();

        $client = $this->client($provider);
        $apiKey = $this->findApiKey($provider) ?? throw ApiKeyNotSet::forProvider($provider);

        return $client->generate($request, $selection->getModel(), $apiKey);
    }

    private function client(LlmProvider $provider): LlmClientInterface
    {
        foreach ($this->clients as $index => $client) {
            if ($provider->value === $index) {
                return $client;
            }
        }

        throw LlmClientNotFound::forProvider($provider);
    }
}
