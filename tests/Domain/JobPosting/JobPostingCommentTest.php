<?php

declare(strict_types=1);

namespace App\Tests\Domain\JobPosting;

use App\Domain\JobPosting\CommentTooLong;
use App\Domain\JobPosting\JobPostingComment;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class JobPostingCommentTest extends TestCase
{
    public function testTrimsSurroundingWhitespaceOnly(): void
    {
        self::assertSame(
            "Ask about the rate.\nApply on Monday.",
            JobPostingComment::fromInput("\u{00A0} Ask about the rate.\nApply on Monday.\n")?->value,
        );
    }

    #[TestWith([''])]
    #[TestWith(["  \t\n"])]
    #[TestWith(["\u{00A0}\u{200B}"])]
    public function testBlankInputIsNoComment(string $text): void
    {
        self::assertNull(JobPostingComment::fromInput($text));
    }

    public function testAcceptsTheMaximumLengthInCharacters(): void
    {
        $text = str_repeat('я', JobPostingComment::MAX_LENGTH);

        self::assertSame($text, JobPostingComment::fromInput($text)?->value);
    }

    public function testRejectsALongerComment(): void
    {
        $this->expectException(CommentTooLong::class);

        JobPostingComment::fromInput(str_repeat('a', JobPostingComment::MAX_LENGTH + 1));
    }
}
