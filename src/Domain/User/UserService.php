<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\Email;
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

    public function create(string $email, #[\SensitiveParameter] string $plainPassword): User
    {
        if ($this->users->exists()) {
            throw UserAlreadyExists::create();
        }

        $userEmail = Email::fromString($email);
        $password = PlainPassword::fromString($plainPassword);

        $user = new User($userEmail, $this->passwordHasher->hash($password));
        $this->users->save($user);

        return $user;
    }
}
