<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\Email;
use App\Entity\User;

interface UserServiceInterface
{
    /**
     * @throws UserAlreadyExists
     */
    public function create(Email $email, PlainPassword $password): User;

    /**
     * A string, not a VO: the hash comes from the configured hasher, not from user input.
     */
    public function upgradePasswordHash(User $user, #[\SensitiveParameter] string $passwordHash): void;
}
