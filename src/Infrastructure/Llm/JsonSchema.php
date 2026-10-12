<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

final readonly class JsonSchema
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $schema): self
    {
        try {
            $decoded = json_decode($schema, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw InvalidJsonSchema::notJson($exception);
        }

        if (!$decoded instanceof \stdClass) {
            throw InvalidJsonSchema::notAnObject();
        }

        return new self($schema);
    }
}
