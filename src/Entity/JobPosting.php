<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\JobPosting\InvalidJobPostingTransition;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Domain\JobPosting\StatusReason;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A posting goes raw → rejected or filtered → evaluated, and fails for good from raw or filtered. The reason is set
 * together with every final status and the verdict only with `evaluated`, so neither can disagree with the status.
 */
#[ORM\Entity]
final class JobPosting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: JobPostingDetails::MAX_LINE_LENGTH)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: JobPostingDetails::MAX_URL_LENGTH)]
    private string $url;

    #[ORM\Column(length: JobPostingDetails::MAX_LINE_LENGTH, nullable: true)]
    private ?string $company;

    #[ORM\Column(length: JobPostingDetails::MAX_LINE_LENGTH, nullable: true)]
    private ?string $location;

    #[ORM\Column(length: 32, enumType: JobPostingStatus::class)]
    private JobPostingStatus $status = JobPostingStatus::Raw;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statusReason = null;

    #[ORM\Column(length: 32, nullable: true, enumType: JobPostingVerdict::class)]
    private ?JobPostingVerdict $verdict = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Platform::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Platform $platform,
        JobPostingDetails $details,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->title = $details->title;
        $this->description = $details->description;
        $this->url = $details->url;
        $this->company = $details->company;
        $this->location = $details->location;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlatform(): Platform
    {
        return $this->platform;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getStatus(): JobPostingStatus
    {
        return $this->status;
    }

    /**
     * Null until the posting reaches a final status.
     */
    public function getStatusReason(): ?string
    {
        return $this->statusReason;
    }

    /**
     * The LLM's verdict; null unless the posting is evaluated.
     */
    public function getVerdict(): ?JobPostingVerdict
    {
        return $this->verdict;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Cut off by the keyword filter.
     *
     * @throws InvalidJobPostingTransition
     */
    public function markRejected(StatusReason $reason): void
    {
        $this->transition(JobPostingStatus::Rejected, JobPostingStatus::Raw);

        $this->statusReason = $reason->value;
    }

    /**
     * Passed the keyword filter, waiting for the LLM.
     *
     * @throws InvalidJobPostingTransition
     */
    public function markFiltered(): void
    {
        $this->transition(JobPostingStatus::Filtered, JobPostingStatus::Raw);
    }

    /**
     * A dislike is the cutoff at the LLM stage. An evaluated posting is not re-evaluated.
     *
     * @throws InvalidJobPostingTransition
     */
    public function markEvaluated(JobPostingVerdict $verdict, StatusReason $reason): void
    {
        $this->transition(JobPostingStatus::Evaluated, JobPostingStatus::Filtered);

        $this->verdict = $verdict;
        $this->statusReason = $reason->value;
    }

    /**
     * Failed for good at the keyword filter or the LLM stage, so it is not mistaken for one still waiting.
     *
     * @throws InvalidJobPostingTransition
     */
    public function markFailed(StatusReason $reason): void
    {
        $this->transition(JobPostingStatus::Failed, JobPostingStatus::Raw, JobPostingStatus::Filtered);

        $this->statusReason = $reason->value;
    }

    private function transition(JobPostingStatus $to, JobPostingStatus ...$allowedFrom): void
    {
        if (!\in_array($this->status, $allowedFrom, true)) {
            throw InvalidJobPostingTransition::create($this->status, $to);
        }

        $this->status = $to;
    }
}
