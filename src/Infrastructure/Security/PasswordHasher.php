<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\User\PlainPassword;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class PasswordHasher implements PasswordHasherInterface
{
    public function __construct(
        private PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function hash(PlainPassword $password): string
    {
        return $this->hasherFactory->getPasswordHasher(User::class)->hash($password->value());
    }
}
