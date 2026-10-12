<?php

declare(strict_types=1);

namespace App\Domain\Llm;

interface LlmServiceInterface
{
    public function saveApiKey(LlmProvider $provider, ApiKey $apiKey): void;

    public function findApiKey(LlmProvider $provider): ?ApiKey;
}
