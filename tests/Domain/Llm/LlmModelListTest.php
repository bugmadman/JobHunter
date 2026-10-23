<?php

declare(strict_types=1);

namespace App\Tests\Domain\Llm;

use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelList;
use PHPUnit\Framework\TestCase;

final class LlmModelListTest extends TestCase
{
    public function testContainsAModelWithTheSameName(): void
    {
        $list = new LlmModelList(LlmModel::fromString('gemini-2.5-flash'), LlmModel::fromString('gemini-2.5-pro'));

        self::assertTrue($list->contains(LlmModel::fromString('gemini-2.5-pro')));
        self::assertFalse($list->contains(LlmModel::fromString('gemini-1.5-pro')));
    }

    public function testAnEmptyListContainsNothing(): void
    {
        self::assertFalse(new LlmModelList()->contains(LlmModel::fromString('gemini-2.5-flash')));
    }
}
