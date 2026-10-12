<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LlmModelSelection>
 */
final class LlmModelSelectionRepository extends ServiceEntityRepository implements LlmModelSelectionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LlmModelSelection::class);
    }

    public function findByTask(LlmTask $task): ?LlmModelSelection
    {
        return $this->findOneBy(['task' => $task]);
    }

    public function findConfiguredTasks(): array
    {
        return array_map(
            static fn (LlmModelSelection $selection): LlmTask => $selection->getTask(),
            $this->findAll(),
        );
    }

    public function save(LlmModelSelection $selection): void
    {
        $this->getEntityManager()->persist($selection);
        $this->getEntityManager()->flush();
    }

    public function remove(LlmModelSelection $selection): void
    {
        $this->getEntityManager()->remove($selection);
        $this->getEntityManager()->flush();
    }
}
