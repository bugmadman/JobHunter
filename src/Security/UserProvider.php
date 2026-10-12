<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\EntityUserProvider;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The entity provider upgrades passwords only through the repository, and a repository calling the user service
 * that depends on it would be a circular dependency. So this provider loads users through the entity provider
 * and hands the upgrade to the service.
 *
 * @implements UserProviderInterface<User>
 */
final readonly class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    /**
     * @var EntityUserProvider<User>
     */
    private EntityUserProvider $entities;

    public function __construct(
        ManagerRegistry $registry,
        private UserServiceInterface $users,
    ) {
        $this->entities = new EntityUserProvider($registry, User::class);
    }

    public function loadUserByIdentifier(string $identifier): User
    {
        return $this->entities->loadUserByIdentifier($identifier);
    }

    public function refreshUser(UserInterface $user): User
    {
        return $this->entities->refreshUser($user);
    }

    public function supportsClass(string $class): bool
    {
        return $this->entities->supportsClass($class);
    }

    public function upgradePassword(
        PasswordAuthenticatedUserInterface $user,
        #[\SensitiveParameter] string $newHashedPassword,
    ): void {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $this->users->upgradePasswordHash($user, $newHashedPassword);
    }
}
