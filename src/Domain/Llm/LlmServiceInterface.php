<?php

declare(strict_types=1);

namespace App\Domain\Llm;

interface LlmServiceInterface
{
    public function saveApiKey(LlmProvider $provider, ApiKey $apiKey): void;

    public function findApiKey(LlmProvider $provider): ?ApiKey;

    public function deleteApiKey(LlmProvider $provider): void;

    public function selectModel(LlmTask $task, LlmProvider $provider, LlmModel $model): void;

    public function deleteModelSelection(LlmTask $task): void;
}
