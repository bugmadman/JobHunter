<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Platform;
use App\Entity\Prompt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prompt>
 */
final class PromptRepository extends ServiceEntityRepository implements PromptRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prompt::class);
    }

    public function findById(int $id): ?Prompt
    {
        return $this->find($id);
    }

    public function findByPlatform(Platform $platform): ?Prompt
    {
        return $this->findOneBy(['platform' => $platform]);
    }

    public function save(Prompt $prompt): void
    {
        $this->getEntityManager()->persist($prompt);
        $this->getEntityManager()->flush();
    }
}
