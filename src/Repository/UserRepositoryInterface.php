<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User\IntakeApiKey;
use App\Entity\User;

interface UserRepositoryInterface
{
    public function exists(): bool;

    public function findByApiKey(IntakeApiKey $apiKey): ?User;

    public function save(User $user): void;
}
