<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

final class EmptyLlmAttachment extends \DomainException
{
    public static function create(): self
    {
        return new self('The LLM attachment must not be empty.');
    }
}
