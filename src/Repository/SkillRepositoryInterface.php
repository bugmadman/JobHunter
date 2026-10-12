<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Skill;

interface SkillRepositoryInterface
{
    public function save(Skill $skill): void;
}
