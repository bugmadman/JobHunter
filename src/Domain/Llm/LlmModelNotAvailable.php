<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final class LlmModelNotAvailable extends \DomainException
{
    public static function forProvider(LlmProvider $provider, LlmModel $model): self
    {
        return new self(\sprintf(
            'The "%s" model is not in the model list of the "%s" LLM provider.',
            $model->value,
            $provider->value,
        ));
    }
}
