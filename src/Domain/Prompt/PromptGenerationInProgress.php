<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

final class PromptGenerationInProgress extends \DomainException
{
    public static function forPlatform(string $platformName): self
    {
        return new self(\sprintf('A prompt for the platform "%s" is being generated.', $platformName));
    }
}
