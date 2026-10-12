<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final class ApiKeyNotSet extends \RuntimeException
{
    public static function forProvider(LlmProvider $provider): self
    {
        return new self(\sprintf('No API key is set for the "%s" LLM provider.', $provider->value));
    }
}
