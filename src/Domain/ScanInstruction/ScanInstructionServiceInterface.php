<?php

declare(strict_types=1);

namespace App\Domain\ScanInstruction;

use App\Entity\Platform;
use App\Entity\ScanInstruction;

interface ScanInstructionServiceInterface
{
    /**
     * Creates the platform's scan instruction or replaces its text, and saves it.
     */
    public function save(Platform $platform, ScanInstructionText $text): void;

    public function delete(ScanInstruction $scanInstruction): void;
}
