<?php

declare(strict_types=1);

namespace App\Domain\Llm;

use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Llm\LlmResponse;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\InvalidEncryptionKey;

interface LlmServiceInterface
{
    public function saveApiKey(LlmProvider $provider, ApiKey $apiKey): void;

    public function findApiKey(LlmProvider $provider): ?ApiKey;

    public function deleteApiKey(LlmProvider $provider): void;

    /**
     * Cached for 24 hours per provider; a failure is not cached.
     *
     * @throws LlmClientNotFound
     * @throws ApiKeyNotSet
     * @throws ApiKeyDecryptionFailed
     * @throws InvalidEncryptionKey
     * @throws LlmRequestFailed
     */
    public function listModels(LlmProvider $provider): LlmModelList;

    /**
     * The next listModels() call loads the list from the provider again.
     */
    public function forgetModelList(LlmProvider $provider): void;

    /**
     * @throws LlmModelNotAvailable
     * @throws LlmClientNotFound
     * @throws ApiKeyNotSet
     * @throws ApiKeyDecryptionFailed
     * @throws InvalidEncryptionKey
     * @throws LlmRequestFailed
     */
    public function selectModel(LlmTask $task, LlmProvider $provider, LlmModel $model): void;

    public function deleteModelSelection(LlmTask $task): void;

    /**
     * @throws LlmModelNotSelected
     * @throws LlmClientNotFound
     * @throws ApiKeyNotSet
     * @throws ApiKeyDecryptionFailed
     * @throws InvalidEncryptionKey
     * @throws LlmRequestFailed
     */
    public function generate(LlmTask $task, LlmRequest $request): LlmResponse;
}
