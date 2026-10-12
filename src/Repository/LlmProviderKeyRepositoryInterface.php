<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Llm\LlmProvider;
use App\Entity\LlmProviderKey;

interface LlmProviderKeyRepositoryInterface
{
    public function findByProvider(LlmProvider $provider): ?LlmProviderKey;

    public function save(LlmProviderKey $providerKey): void;
}
