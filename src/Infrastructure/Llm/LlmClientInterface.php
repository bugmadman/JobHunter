<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelList;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface LlmClientInterface
{
    /**
     * @throws LlmRequestFailed
     */
    public function generate(LlmRequest $request, LlmModel $model, ApiKey $apiKey): LlmResponse;

    /**
     * Only the models generate() can call: a provider's full list also has embedding, image and audio models.
     *
     * @throws LlmRequestFailed
     */
    public function listModels(ApiKey $apiKey): LlmModelList;
}
