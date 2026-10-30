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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class LlmService implements LlmServiceInterface
{
    private const string MODEL_LIST_CACHE_KEY = 'llm.model_list.%s';
    private const int MODEL_LIST_TTL = 24 * 60 * 60;

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
        private CacheInterface $cache,
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
        $this->forgetModelList($provider);
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
            $this->forgetModelList($provider);
        }
    }

    public function listModels(LlmProvider $provider): LlmModelList
    {
        // Only the names are cached: the VOs would be stored as serialized objects, which break on unserialize once
        // their class changes and skip the validation of LlmModel::fromString().
        $names = $this->cache->get(
            self::modelListCacheKey($provider),
            function (ItemInterface $item, bool &$save) use ($provider): array {
                $item->expiresAfter(self::MODEL_LIST_TTL);

                $client = $this->client($provider);
                $apiKey = $this->findApiKey($provider) ?? throw ApiKeyNotSet::forProvider($provider);
                $names = array_map(
                    static fn (LlmModel $model): string => $model->value,
                    iterator_to_array($client->listModels($apiKey), false),
                );
                // A working key always has text models, so an empty answer is a provider glitch, not a day's result.
                $save = [] !== $names;

                return $names;
            },
        );

        return new LlmModelList(...array_map(LlmModel::fromString(...), $names));
    }

    public function forgetModelList(LlmProvider $provider): void
    {
        $this->cache->delete(self::modelListCacheKey($provider));
    }

    public function selectModel(LlmTask $task, LlmProvider $provider, LlmModel $model): void
    {
        if (!$this->listModels($provider)->contains($model)) {
            throw LlmModelNotAvailable::forProvider($provider, $model);
        }

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

    private static function modelListCacheKey(LlmProvider $provider): string
    {
        return \sprintf(self::MODEL_LIST_CACHE_KEY, $provider->value);
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
