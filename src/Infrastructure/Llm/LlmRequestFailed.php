<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use App\Domain\Llm\LlmProvider;

final class LlmRequestFailed extends \RuntimeException
{
    public static function create(LlmProvider $provider, string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The %s request failed: %s', $provider->value, $reason), 0, $previous);
    }
}
