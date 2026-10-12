<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Llm;

use App\Infrastructure\Llm\JsonSchema;
use App\Infrastructure\Llm\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

final class JsonSchemaValidatorTest extends TestCase
{
    private const string SCHEMA = <<<'JSON'
        {
            "type": "object",
            "properties": {"seniority": {"type": "string", "enum": ["junior", "senior"]}},
            "required": ["seniority"]
        }
        JSON;

    public function testMatchingJsonHasNoErrors(): void
    {
        self::assertSame([], $this->validate('{"seniority": "senior"}'));
    }

    public function testInvalidJsonIsOneError(): void
    {
        self::assertSame(['Not valid JSON: Syntax error'], $this->validate('{"seniority":'));
    }

    public function testViolationNamesTheProperty(): void
    {
        $errors = $this->validate('{"seniority": "lead"}');

        self::assertCount(1, $errors);
        self::assertStringStartsWith('seniority: ', $errors[0]);
    }

    public function testViolationAtTheTopLevelIsNamedRoot(): void
    {
        $errors = $this->validate('[]');

        self::assertNotEmpty($errors);
        self::assertStringStartsWith('(root): ', $errors[0]);
    }

    /**
     * @return list<string>
     */
    private function validate(string $json): array
    {
        return new JsonSchemaValidator()->validate($json, JsonSchema::fromString(self::SCHEMA));
    }
}
