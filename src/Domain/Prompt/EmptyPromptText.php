<?php

declare(strict_types=1);

namespace App\Domain\Prompt;

final class EmptyPromptText extends \DomainException
{
    public static function create(): self
    {
        return new self('The prompt text must not be empty.');
    }
}
