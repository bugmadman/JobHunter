<?php

declare(strict_types=1);

namespace App\Tests\Domain\JobPosting;

use App\Domain\JobPosting\EmptyStatusReason;
use App\Domain\JobPosting\StatusReason;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class StatusReasonTest extends TestCase
{
    public function testTrimsSurroundingWhitespaceOnly(): void
    {
        self::assertSame(
            "No PHP.\nWordPress only.",
            StatusReason::fromString("\u{00A0} No PHP.\nWordPress only.\n")->value,
        );
    }

    #[TestWith([''])]
    #[TestWith(["  \t\n"])]
    #[TestWith(["\u{00A0}\u{200B}"])]
    public function testRejectsAnEmptyReason(string $reason): void
    {
        $this->expectException(EmptyStatusReason::class);

        StatusReason::fromString($reason);
    }
}
