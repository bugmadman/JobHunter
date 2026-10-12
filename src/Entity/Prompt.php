<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Llm\LlmOperationStatus;
use App\Domain\Prompt\CvProfileNotAnalysed;
use App\Domain\Prompt\PromptGenerationAlreadyFinished;
use App\Domain\Prompt\PromptGenerationInProgress;
use App\Domain\Prompt\PromptSource;
use App\Domain\Prompt\PromptText;
use App\Repository\PromptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The current prompt of a platform, one per platform. The text and its source describe the prompt in use; the
 * generation status and CV profile describe the latest generation, so a failed regeneration keeps the old text.
 */
#[ORM\Entity(repositoryClass: PromptRepository::class)]
final class Prompt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    #[ORM\Column(length: 32, nullable: true, enumType: PromptSource::class)]
    private ?PromptSource $source = null;

    #[ORM\Column(length: 32, enumType: LlmOperationStatus::class)]
    private LlmOperationStatus $generationStatus = LlmOperationStatus::Pending;

    #[ORM\ManyToOne(targetEntity: CvProfile::class)]
    #[ORM\JoinColumn(nullable: false)]
    private CvProfile $cvProfile;

    /**
     * A prompt comes into being with its first generation request.
     *
     * @throws CvProfileNotAnalysed
     */
    public function __construct(
        #[ORM\OneToOne(targetEntity: Platform::class)]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private Platform $platform,
        CvProfile $cvProfile,
    ) {
        self::assertAnalysed($cvProfile);

        $this->cvProfile = $cvProfile;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlatform(): Platform
    {
        return $this->platform;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getSource(): ?PromptSource
    {
        return $this->source;
    }

    public function getGenerationStatus(): LlmOperationStatus
    {
        return $this->generationStatus;
    }

    /**
     * The CV profile of the latest generation request; after a failed regeneration the text predates it.
     */
    public function getCvProfile(): CvProfile
    {
        return $this->cvProfile;
    }

    /**
     * The current text stays in use until the new draft is ready.
     *
     * @throws PromptGenerationInProgress
     * @throws CvProfileNotAnalysed
     */
    public function requestGeneration(CvProfile $cvProfile): void
    {
        $this->assertNotGenerating();
        self::assertAnalysed($cvProfile);

        $this->generationStatus = LlmOperationStatus::Pending;
        $this->cvProfile = $cvProfile;
    }

    /**
     * @throws PromptGenerationAlreadyFinished
     */
    public function markGenerated(PromptText $text): void
    {
        $this->assertGenerationPending();

        $this->generationStatus = LlmOperationStatus::Done;
        $this->text = $text->value;
        $this->source = PromptSource::Generated;
    }

    /**
     * @throws PromptGenerationAlreadyFinished
     */
    public function markGenerationFailed(): void
    {
        $this->assertGenerationPending();

        $this->generationStatus = LlmOperationStatus::Failed;
    }

    /**
     * Not while generating: the draft would silently overwrite the edit.
     *
     * @throws PromptGenerationInProgress
     */
    public function edit(PromptText $text): void
    {
        $this->assertNotGenerating();

        $this->text = $text->value;
        $this->source = PromptSource::Edited;
    }

    /**
     * @throws PromptGenerationAlreadyFinished
     */
    public function assertGenerationPending(): void
    {
        if (LlmOperationStatus::Pending !== $this->generationStatus) {
            throw PromptGenerationAlreadyFinished::withStatus($this->generationStatus);
        }
    }

    private function assertNotGenerating(): void
    {
        if (LlmOperationStatus::Pending === $this->generationStatus) {
            throw PromptGenerationInProgress::forPlatform($this->platform->getName());
        }
    }

    private static function assertAnalysed(CvProfile $cvProfile): void
    {
        $status = $cvProfile->getAnalysisStatus();

        if (LlmOperationStatus::Done !== $status) {
            throw CvProfileNotAnalysed::withStatus($status);
        }
    }
}
