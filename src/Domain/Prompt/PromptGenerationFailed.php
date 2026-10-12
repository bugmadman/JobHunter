<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

/**
 * A failure that a retry won't fix: the generation is already marked failed when this is thrown.
 */
final class PromptGenerationFailed extends \RuntimeException
{
    public static function because(\Throwable $previous): self
    {
        return new self('The prompt generation failed: '.$previous->getMessage(), 0, $previous);
    }
}
