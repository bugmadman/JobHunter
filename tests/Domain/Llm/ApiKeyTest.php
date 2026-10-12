<?php

declare(strict_types=1);

namespace App\Tests\Domain\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\EmptyApiKey;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('AIza-secret', ApiKey::fromString(" AIza-secret\n")->value());
    }

    public function testJsonEncodingDoesNotExposeTheKey(): void
    {
        $json = json_encode(ApiKey::fromString('AIza-secret'), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('AIza-secret', $json);
    }

    #[TestWith([''])]
    #[TestWith(["  \t\n"])]
    public function testRejectsAnEmptyKey(string $apiKey): void
    {
        $this->expectException(EmptyApiKey::class);

        ApiKey::fromString($apiKey);
    }
}
