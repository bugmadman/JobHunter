<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final class LlmClientNotFound extends \LogicException
{
    public static function forProvider(LlmProvider $provider): self
    {
        return new self(\sprintf('No LLM client is registered for the "%s" provider.', $provider->value));
    }
}
