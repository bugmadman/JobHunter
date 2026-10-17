<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScanInstruction;

interface ScanInstructionRepositoryInterface
{
    public function save(ScanInstruction $scanInstruction): void;
}
