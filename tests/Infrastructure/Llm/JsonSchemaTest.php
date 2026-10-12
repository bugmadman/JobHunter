<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Llm;

use App\Infrastructure\Llm\InvalidJsonSchema;
use App\Infrastructure\Llm\JsonSchema;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class JsonSchemaTest extends TestCase
{
    public function testKeepsTheSchemaAsGiven(): void
    {
        $schema = '{"type": "object", "properties": {"seniority": {"type": "string"}}}';

        self::assertSame($schema, JsonSchema::fromString($schema)->value);
    }

    #[TestWith([''])]
    #[TestWith(['{"type": "object"'])]
    #[TestWith(['not json'])]
    public function testRejectsInvalidJson(string $schema): void
    {
        $this->expectException(InvalidJsonSchema::class);
        $this->expectExceptionMessage('not valid JSON');

        JsonSchema::fromString($schema);
    }

    #[TestWith(['[]'])]
    #[TestWith(['true'])]
    #[TestWith(['"object"'])]
    #[TestWith(['null'])]
    public function testRejectsJsonThatIsNotAnObject(string $schema): void
    {
        $this->expectException(InvalidJsonSchema::class);
        $this->expectExceptionMessage('must be a JSON object');

        JsonSchema::fromString($schema);
    }
}
