<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\InvalidEmail;
use App\Entity\User;

interface UserServiceInterface
{
    /**
     * @throws UserAlreadyExists
     * @throws InvalidEmail
     * @throws EmptyPassword
     */
    public function create(string $email, string $plainPassword): User;
}
