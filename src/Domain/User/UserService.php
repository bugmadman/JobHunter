<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\Email;
use App\Domain\Shared\Locale;
use App\Entity\User;
use App\Infrastructure\Security\PasswordHasherInterface;
use App\Repository\UserRepositoryInterface;

final readonly class UserService implements UserServiceInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordHasherInterface $passwordHasher,
    ) {
    }

    public function create(Email $email, PlainPassword $password): User
    {
        if ($this->users->exists()) {
            throw UserAlreadyExists::create();
        }

        $user = new User($email, $this->passwordHasher->hash($password));
        $this->users->save($user);

        return $user;
    }

    public function upgradePasswordHash(User $user, #[\SensitiveParameter] string $passwordHash): void
    {
        $user->changePasswordHash($passwordHash);
        $this->users->save($user);
    }

    public function changeLocale(User $user, Locale $locale): void
    {
        $user->changeLocale($locale);
        $this->users->save($user);
    }
}
