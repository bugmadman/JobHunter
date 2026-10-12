<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Platform;

interface PlatformRepositoryInterface
{
    public function findById(int $id): ?Platform;

    /**
     * @return list<Platform>
     */
    public function findAllOrderedByName(): array;

    public function save(Platform $platform): void;
}
