<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\User\PlainPassword;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface as SymfonyPasswordHasherInterface;

final readonly class PasswordHasher implements PasswordHasherInterface
{
    public function __construct(
        private PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function hash(PlainPassword $password): string
    {
        return $this->userHasher()->hash($password->value());
    }

    public function verify(string $hash, #[\SensitiveParameter] string $password): bool
    {
        return $this->userHasher()->verify($hash, $password);
    }

    public function needsRehash(string $hash): bool
    {
        return $this->userHasher()->needsRehash($hash);
    }

    private function userHasher(): SymfonyPasswordHasherInterface
    {
        return $this->hasherFactory->getPasswordHasher(User::class);
    }
}
