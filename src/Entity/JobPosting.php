<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\JobPosting\InvalidJobPostingTransition;
use App\Domain\JobPosting\JobPostingComment;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingNotEvaluated;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingStatusChanged;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Domain\JobPosting\StatusReason;
use App\Repository\JobPostingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A posting goes raw → rejected or filtered → evaluated, and fails for good from raw or filtered. The reason is set
 * together with every final status and the verdict only with `evaluated`, so neither can disagree with the status.
 * The owner's manual verdict is kept apart from the LLM's, which stays as it was answered.
 * The arrival and every transition are recorded as events, which JobPostingStageLogWriter logs on flush.
 */
#[ORM\Entity(repositoryClass: JobPostingRepository::class)]
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

    #[ORM\Column(length: 32, nullable: true, enumType: JobPostingVerdict::class)]
    private ?JobPostingVerdict $manualVerdict = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /**
     * @var list<JobPostingStatusChanged>
     */
    private array $events = [];

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

        $this->events[] = new JobPostingStatusChanged(null, JobPostingStatus::Raw, null, $createdAt);
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

    public function getDetails(): JobPostingDetails
    {
        return JobPostingDetails::create(
            $this->title,
            $this->description,
            $this->url,
            $this->company,
            $this->location,
        );
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

    /**
     * The owner's override of the LLM's verdict; null while the owner agrees with the LLM.
     */
    public function getManualVerdict(): ?JobPostingVerdict
    {
        return $this->manualVerdict;
    }

    /**
     * The verdict that counts: the owner's override, else the LLM's.
     */
    public function getEffectiveVerdict(): ?JobPostingVerdict
    {
        return $this->manualVerdict ?? $this->verdict;
    }

    public function getComment(): ?string
    {
        return $this->comment;
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
    public function markRejected(StatusReason $reason, \DateTimeImmutable $at): void
    {
        $this->transition(JobPostingStatus::Rejected, $reason, $at, JobPostingStatus::Raw);
    }

    /**
     * Passed the keyword filter, waiting for the LLM.
     *
     * @throws InvalidJobPostingTransition
     */
    public function markFiltered(\DateTimeImmutable $at): void
    {
        $this->transition(JobPostingStatus::Filtered, null, $at, JobPostingStatus::Raw);
    }

    /**
     * A dislike is the cutoff at the LLM stage. An evaluated posting is not re-evaluated.
     *
     * @throws InvalidJobPostingTransition
     */
    public function markEvaluated(JobPostingVerdict $verdict, StatusReason $reason, \DateTimeImmutable $at): void
    {
        $this->transition(JobPostingStatus::Evaluated, $reason, $at, JobPostingStatus::Filtered);

        $this->verdict = $verdict;
    }

    /**
     * The owner's verdict replaces the LLM's; choosing the LLM's own verdict drops the override.
     *
     * @throws JobPostingNotEvaluated
     */
    public function overrideVerdict(JobPostingVerdict $verdict): void
    {
        if (null === $this->verdict) {
            throw JobPostingNotEvaluated::withStatus($this->status);
        }

        $this->manualVerdict = $verdict === $this->verdict ? null : $verdict;
    }

    /**
     * Null removes the comment.
     */
    public function updateComment(?JobPostingComment $comment): void
    {
        $this->comment = $comment?->value;
    }

    /**
     * Failed for good at the keyword filter or the LLM stage, so it is not mistaken for one still waiting.
     *
     * @throws InvalidJobPostingTransition
     */
    public function markFailed(StatusReason $reason, \DateTimeImmutable $at): void
    {
        $this->transition(
            JobPostingStatus::Failed,
            $reason,
            $at,
            JobPostingStatus::Raw,
            JobPostingStatus::Filtered,
        );
    }

    /**
     * The arrival and the transitions since the last call, oldest first; each is returned once.
     *
     * @return list<JobPostingStatusChanged>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function transition(
        JobPostingStatus $to,
        ?StatusReason $reason,
        \DateTimeImmutable $at,
        JobPostingStatus ...$allowedFrom,
    ): void {
        if (!\in_array($this->status, $allowedFrom, true)) {
            throw InvalidJobPostingTransition::create($this->status, $to);
        }

        $this->events[] = new JobPostingStatusChanged($this->status, $to, $reason, $at);
        $this->status = $to;
        $this->statusReason = $reason?->value;
    }
}
