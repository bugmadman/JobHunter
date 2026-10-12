<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Llm;

use App\Infrastructure\Llm\EmptyLlmAttachment;
use App\Infrastructure\Llm\LlmAttachment;
use App\Infrastructure\Llm\LlmAttachmentType;
use PHPUnit\Framework\TestCase;

final class LlmAttachmentTest extends TestCase
{
    public function testKeepsTheTypeAndContents(): void
    {
        $attachment = LlmAttachment::fromContents(LlmAttachmentType::Pdf, "%PDF-1.7\n");

        self::assertSame(LlmAttachmentType::Pdf, $attachment->type);
        self::assertSame("%PDF-1.7\n", $attachment->contents);
    }

    public function testRejectsEmptyContents(): void
    {
        $this->expectException(EmptyLlmAttachment::class);

        LlmAttachment::fromContents(LlmAttachmentType::Pdf, '');
    }
}
