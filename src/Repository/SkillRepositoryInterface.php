<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Skill\SkillName;
use App\Entity\Skill;

interface SkillRepositoryInterface
{
    /**
     * @param list<SkillName> $names
     *
     * @return list<Skill> only the skills that exist, in no particular order
     */
    public function findByNames(array $names): array;

    public function save(Skill $skill): void;
}
