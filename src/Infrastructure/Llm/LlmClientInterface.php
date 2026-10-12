<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\LlmModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface LlmClientInterface
{
    /**
     * @throws LlmRequestFailed
     */
    public function generate(LlmRequest $request, LlmModel $model, ApiKey $apiKey): LlmResponse;
}
