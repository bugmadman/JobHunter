<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final class EmptyLlmModel extends \DomainException
{
    public static function create(): self
    {
        return new self('The LLM model must not be empty.');
    }
}
