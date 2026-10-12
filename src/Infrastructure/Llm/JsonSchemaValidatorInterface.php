<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

interface JsonSchemaValidatorInterface
{
    /**
     * @return list<string> one message per violation, empty when the JSON matches the schema
     */
    public function validate(string $json, JsonSchema $schema): array;
}
