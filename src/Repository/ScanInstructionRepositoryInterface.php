<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Platform;
use App\Entity\ScanInstruction;

interface ScanInstructionRepositoryInterface
{
    public function findByPlatform(Platform $platform): ?ScanInstruction;

    /**
     * @return list<Platform> ordered by name
     */
    public function findPlatformsWithoutInstruction(): array;

    public function save(ScanInstruction $scanInstruction): void;

    public function remove(ScanInstruction $scanInstruction): void;
}
