<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\ScanInstruction;

final readonly class PlatformScanInstruction
{
    private function __construct(
        #[ApiProperty(description: 'The platform\'s name, to send as is in the "platform" field of the intake.')]
        public string $platform,
        #[ApiProperty(description: 'How to scan the platform: the searches, the fields to collect, the limits.')]
        public string $instruction,
        #[ApiProperty(description: 'When the owner last changed the instruction.')]
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromScanInstruction(ScanInstruction $instruction): self
    {
        return new self(
            $instruction->getPlatform()->getName(),
            $instruction->getText(),
            $instruction->getUpdatedAt(),
        );
    }
}
