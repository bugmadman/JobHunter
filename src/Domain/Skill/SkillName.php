<?php

declare(strict_types=1);

namespace App\Domain\Skill;

use App\Entity\Skill;

final readonly class SkillName
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(
        public string $value,
    ) {
    }

    /**
     * The canonical form is lowercase with single spaces, so "PHP", " php" and "Php" are one skill: the unique
     * `skill.name` index compares bytes, and the keyword filter matches the same lowercase form.
     */
    public static function fromString(string $name): self
    {
        $name = mb_strtolower(trim((string) preg_replace('/[\s\p{Z}\p{Cc}\p{Cf}]+/u', ' ', $name)));

        if ('' === $name || mb_strlen($name) > Skill::MAX_NAME_LENGTH) {
            throw InvalidSkillName::create($name);
        }

        return new self($name);
    }
}
