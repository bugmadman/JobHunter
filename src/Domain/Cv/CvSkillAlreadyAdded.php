<?php

declare(strict_types=1);

namespace App\Domain\Cv;

use App\Domain\Skill\SkillName;

final class CvSkillAlreadyAdded extends \DomainException
{
    public static function withName(SkillName $name): self
    {
        return new self(\sprintf('The CV already has the skill "%s".', $name->value));
    }
}
