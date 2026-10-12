<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Llm\LlmProvider;
use App\Entity\LlmProviderKey;

interface LlmProviderKeyRepositoryInterface
{
    public function findByProvider(LlmProvider $provider): ?LlmProviderKey;

    /**
     * @return list<LlmProvider>
     */
    public function findConfiguredProviders(): array;

    public function save(LlmProviderKey $providerKey): void;

    public function remove(LlmProviderKey $providerKey): void;
}
