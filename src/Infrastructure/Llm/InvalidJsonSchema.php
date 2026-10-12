<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

final class InvalidJsonSchema extends \DomainException
{
    public static function notJson(\JsonException $previous): self
    {
        return new self('The JSON schema is not valid JSON: '.$previous->getMessage(), 0, $previous);
    }

    public static function notAnObject(): self
    {
        return new self('The JSON schema must be a JSON object.');
    }
}
