<?php

declare(strict_types=1);

namespace App\Domain\Skill;

use App\Entity\Skill;

final class InvalidSkillName extends \DomainException
{
    public static function create(string $name): self
    {
        return new self(\sprintf(
            'A skill name must be 1 to %d characters long, got %d.',
            Skill::MAX_NAME_LENGTH,
            mb_strlen($name),
        ));
    }
}
