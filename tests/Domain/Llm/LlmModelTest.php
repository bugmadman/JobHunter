<?php

declare(strict_types=1);

namespace App\Tests\Domain\Llm;

use App\Domain\Llm\EmptyLlmModel;
use App\Domain\Llm\LlmModel;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class LlmModelTest extends TestCase
{
    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('gemini-2.5-flash', LlmModel::fromString(" gemini-2.5-flash\n")->value);
    }

    #[TestWith([''])]
    #[TestWith(["  \t\n"])]
    public function testRejectsAnEmptyModel(string $model): void
    {
        $this->expectException(EmptyLlmModel::class);

        LlmModel::fromString($model);
    }
}
