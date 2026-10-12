<?php

declare(strict_types=1);

namespace App\Domain\Llm;

final class EmptyApiKey extends \DomainException
{
    public static function create(): self
    {
        return new self('The API key must not be empty.');
    }
}
