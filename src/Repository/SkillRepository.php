<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Skill\SkillName;
use App\Entity\Skill;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Skill>
 */
final class SkillRepository extends ServiceEntityRepository implements SkillRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Skill::class);
    }

    public function findByNames(array $names): array
    {
        if ([] === $names) {
            return [];
        }

        return $this->findBy(['name' => array_map(static fn (SkillName $name): string => $name->value, $names)]);
    }

    public function save(Skill $skill): void
    {
        $this->getEntityManager()->persist($skill);
        $this->getEntityManager()->flush();
    }
}
