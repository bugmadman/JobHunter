<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Llm\LlmProvider;
use App\Entity\LlmProviderKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LlmProviderKey>
 */
final class LlmProviderKeyRepository extends ServiceEntityRepository implements LlmProviderKeyRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LlmProviderKey::class);
    }

    public function findByProvider(LlmProvider $provider): ?LlmProviderKey
    {
        return $this->findOneBy(['provider' => $provider]);
    }

    public function findConfiguredProviders(): array
    {
        return array_map(
            static fn (LlmProviderKey $providerKey): LlmProvider => $providerKey->getProvider(),
            $this->findAll(),
        );
    }

    public function save(LlmProviderKey $providerKey): void
    {
        $this->getEntityManager()->persist($providerKey);
        $this->getEntityManager()->flush();
    }

    public function remove(LlmProviderKey $providerKey): void
    {
        $this->getEntityManager()->remove($providerKey);
        $this->getEntityManager()->flush();
    }
}
