<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\StatusReason;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pipeline transition of a posting. `from` is null for the entry written when the posting arrives.
 */
#[ORM\Entity]
final class JobPostingStageLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: JobPosting::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private JobPosting $jobPosting,
        #[ORM\Column(name: 'from_status', length: 32, nullable: true, enumType: JobPostingStatus::class)]
        private ?JobPostingStatus $from,
        #[ORM\Column(name: 'to_status', length: 32, enumType: JobPostingStatus::class)]
        private JobPostingStatus $to,
        ?StatusReason $reason,
        #[ORM\Column]
        private \DateTimeImmutable $occurredAt,
    ) {
        $this->reason = $reason?->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJobPosting(): JobPosting
    {
        return $this->jobPosting;
    }

    public function getFrom(): ?JobPostingStatus
    {
        return $this->from;
    }

    public function getTo(): JobPostingStatus
    {
        return $this->to;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
