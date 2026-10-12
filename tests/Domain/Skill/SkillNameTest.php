<?php

declare(strict_types=1);

namespace App\Tests\Domain\Skill;

use App\Domain\Skill\InvalidSkillName;
use App\Domain\Skill\SkillName;
use App\Entity\Skill;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SkillNameTest extends TestCase
{
    #[TestWith(['PHP', 'php'])]
    #[TestWith(["  PostgreSQL\n", 'postgresql'])]
    #[TestWith(["Ruby \t on\u{00A0}Rails", 'ruby on rails'])]
    #[TestWith(['ЯНДЕКС.Облако', 'яндекс.облако'])]
    #[TestWith(['C#', 'c#'])]
    #[TestWith(["Sym\0fony", 'sym fony'])]
    #[TestWith(["PHP\u{200B}", 'php'])]
    public function testNameIsCanonicalised(string $name, string $expected): void
    {
        self::assertSame($expected, SkillName::fromString($name)->value);
    }

    public function testNameOfMaxLengthInCharactersIsAccepted(): void
    {
        $name = str_repeat('é', Skill::MAX_NAME_LENGTH);

        self::assertSame($name, SkillName::fromString($name)->value);
    }

    #[TestWith([''])]
    #[TestWith([" \t\u{00A0}\n"])]
    #[TestWith(["\0\u{200B}"])]
    public function testBlankNameIsRejected(string $name): void
    {
        $this->expectException(InvalidSkillName::class);

        SkillName::fromString($name);
    }

    public function testTooLongNameIsRejected(): void
    {
        $this->expectException(InvalidSkillName::class);

        SkillName::fromString(str_repeat('a', Skill::MAX_NAME_LENGTH + 1));
    }
}
