<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use App\Domain\Llm\LlmProvider;

final class LlmRequestFailed extends \RuntimeException
{
    private function __construct(LlmProvider $provider, string $reason, private readonly bool $permanent)
    {
        parent::__construct(\sprintf('The %s request failed: %s', $provider->value, $reason));
    }

    public static function permanent(LlmProvider $provider, string $reason): self
    {
        return new self($provider, $reason, true);
    }

    public static function transient(LlmProvider $provider, string $reason): self
    {
        return new self($provider, $reason, false);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }
}
