<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Platform;

interface PlatformRepositoryInterface
{
    public function save(Platform $platform): void;
}
