<?php

declare(strict_types=1);

namespace App\Infrastructure\Llm;

use JsonSchema\Validator;

final readonly class JsonSchemaValidator implements JsonSchemaValidatorInterface
{
    public function validate(string $json, JsonSchema $schema): array
    {
        try {
            $value = json_decode($json, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return ['Not valid JSON: '.$exception->getMessage()];
        }

        $validator = new Validator();
        $validator->validate($value, json_decode($schema->value, flags: \JSON_THROW_ON_ERROR));

        /** @var list<array{property: string, message: string}> $violations */
        $violations = $validator->getErrors();

        $errors = [];
        foreach ($violations as $error) {
            $errors[] = ('' === $error['property'] ? '(root)' : $error['property']).': '.$error['message'];
        }

        return $errors;
    }
}
