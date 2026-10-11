<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Shared\Email;
use App\Domain\Shared\InvalidEmail;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements UserRepositoryInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function loadUserByIdentifier(string $identifier): ?User
    {
        try {
            $email = Email::fromString($identifier);
        } catch (InvalidEmail) {
            return null;
        }

        return $this->findOneBy(['email' => $email->value]);
    }
}
