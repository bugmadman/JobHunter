<?php

declare(strict_types=1);

namespace App\Domain\ScanInstruction;

use App\Entity\Platform;
use App\Entity\ScanInstruction;
use App\Repository\ScanInstructionRepositoryInterface;
use Psr\Clock\ClockInterface;

final readonly class ScanInstructionService implements ScanInstructionServiceInterface
{
    public function __construct(
        private ScanInstructionRepositoryInterface $scanInstructions,
        private ClockInterface $clock,
    ) {
    }

    public function save(Platform $platform, ScanInstructionText $text): void
    {
        $scanInstruction = $this->scanInstructions->findByPlatform($platform);

        if (null === $scanInstruction) {
            $scanInstruction = new ScanInstruction($platform, $text, $this->clock->now());
        } else {
            $scanInstruction->edit($text, $this->clock->now());
        }

        $this->scanInstructions->save($scanInstruction);
    }

    public function delete(ScanInstruction $scanInstruction): void
    {
        $this->scanInstructions->remove($scanInstruction);
    }
}
