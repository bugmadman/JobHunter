<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\ScanInstruction\ScanInstructionText;
use App\Repository\ScanInstructionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the collecting agent does on a platform: the searches, the fields to collect, the limits. Not the matching
 * prompt: that one is read by our LLM, this one by the agent that scans the platform.
 */
#[ORM\Entity(repositoryClass: ScanInstructionRepository::class)]
final class ScanInstruction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    public function __construct(
        #[ORM\OneToOne(targetEntity: Platform::class)]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private Platform $platform,
        ScanInstructionText $text,
        #[ORM\Column]
        private \DateTimeImmutable $updatedAt,
    ) {
        $this->text = $text->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlatform(): Platform
    {
        return $this->platform;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function edit(ScanInstructionText $text, \DateTimeImmutable $now): void
    {
        $this->text = $text->value;
        $this->updatedAt = $now;
    }
}
