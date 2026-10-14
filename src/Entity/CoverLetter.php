<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\CoverLetter\CoverLetterGenerationAlreadyFinished;
use App\Domain\CoverLetter\CoverLetterNotGenerated;
use App\Domain\CoverLetter\CoverLetterText;
use App\Domain\Llm\LlmOperationStatus;
use App\Repository\CoverLetterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One version of a cover letter for a job posting; every generation request creates a new one. The text stays null
 * until the generation is done.
 */
#[ORM\Entity(repositoryClass: CoverLetterRepository::class)]
final class CoverLetter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    #[ORM\Column(length: 32, enumType: LlmOperationStatus::class)]
    private LlmOperationStatus $status = LlmOperationStatus::Pending;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: JobPosting::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private JobPosting $jobPosting,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJobPosting(): JobPosting
    {
        return $this->jobPosting;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getStatus(): LlmOperationStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @throws CoverLetterGenerationAlreadyFinished
     */
    public function markGenerated(CoverLetterText $text): void
    {
        $this->assertGenerationPending();

        $this->status = LlmOperationStatus::Done;
        $this->text = $text->value;
    }

    /**
     * @throws CoverLetterGenerationAlreadyFinished
     */
    public function markGenerationFailed(): void
    {
        $this->assertGenerationPending();

        $this->status = LlmOperationStatus::Failed;
    }

    /**
     * The owner's edit replaces the generated text of this version; it doesn't add a new one.
     *
     * @throws CoverLetterNotGenerated
     */
    public function edit(CoverLetterText $text): void
    {
        if (LlmOperationStatus::Done !== $this->status) {
            throw CoverLetterNotGenerated::withStatus($this->status);
        }

        $this->text = $text->value;
    }

    /**
     * @throws CoverLetterGenerationAlreadyFinished
     */
    public function assertGenerationPending(): void
    {
        if (LlmOperationStatus::Pending !== $this->status) {
            throw CoverLetterGenerationAlreadyFinished::withStatus($this->status);
        }
    }
}
