<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvAnalysisAlreadyFinished;
use App\Domain\Cv\CvFile;
use App\Domain\Cv\LanguageProficiency;
use App\Domain\Cv\SalaryExpectation;
use App\Domain\Cv\Seniority;
use App\Domain\Llm\LlmOperationStatus;
use App\Repository\CvProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CvProfileRepository::class)]
final class CvProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $storedFileName;

    #[ORM\Column(length: 255)]
    private string $originalFileName;

    #[ORM\Column(length: 32, enumType: LlmOperationStatus::class)]
    private LlmOperationStatus $analysisStatus = LlmOperationStatus::Pending;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $analysisOriginal = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $analysisEnglish = null;

    #[ORM\Column(nullable: true)]
    private ?int $yearsExperience = null;

    #[ORM\Column(length: 32, nullable: true, enumType: Seniority::class)]
    private ?Seniority $seniority = null;

    #[ORM\Column(nullable: true)]
    private ?int $salaryMin = null;

    #[ORM\Column(nullable: true)]
    private ?int $salaryMax = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $salaryCurrency = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawLlmResponse = null;

    /**
     * @var Collection<int, CvLanguage>
     */
    #[ORM\OneToMany(targetEntity: CvLanguage::class, mappedBy: 'cvProfile', cascade: ['persist'], orphanRemoval: true)]
    private Collection $languages;

    public function __construct(CvFile $file)
    {
        $this->languages = new ArrayCollection();
        $this->storedFileName = $file->storedName;
        $this->originalFileName = $file->originalName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFile(): CvFile
    {
        return CvFile::create($this->storedFileName, $this->originalFileName);
    }

    public function getAnalysisStatus(): LlmOperationStatus
    {
        return $this->analysisStatus;
    }

    public function getAnalysis(): ?CvAnalysis
    {
        if (LlmOperationStatus::Done !== $this->analysisStatus) {
            return null;
        }

        if (null === $this->analysisOriginal || null === $this->analysisEnglish) {
            throw new \LogicException('A done CV analysis has no text.');
        }

        return new CvAnalysis(
            $this->analysisOriginal,
            $this->analysisEnglish,
            $this->yearsExperience,
            $this->seniority,
            null === $this->salaryCurrency
                ? null
                : SalaryExpectation::create($this->salaryMin, $this->salaryMax, $this->salaryCurrency),
            array_values($this->languages->map(
                static fn (CvLanguage $language): LanguageProficiency => $language->getProficiency(),
            )->toArray()),
        );
    }

    public function getRawLlmResponse(): ?string
    {
        return $this->rawLlmResponse;
    }

    public function markAnalysisDone(CvAnalysis $analysis, string $rawLlmResponse): void
    {
        $this->assertAnalysisPending();

        $this->analysisStatus = LlmOperationStatus::Done;
        $this->analysisOriginal = $analysis->originalLanguageText;
        $this->analysisEnglish = $analysis->englishText;
        $this->yearsExperience = $analysis->yearsExperience;
        $this->seniority = $analysis->seniority;
        $this->salaryMin = $analysis->salaryExpectation?->min;
        $this->salaryMax = $analysis->salaryExpectation?->max;
        $this->salaryCurrency = $analysis->salaryExpectation?->currency;
        $this->rawLlmResponse = $rawLlmResponse;

        foreach ($analysis->languages as $proficiency) {
            $this->languages->add(new CvLanguage($this, $proficiency));
        }
    }

    /**
     * The raw response is kept when there is one: a response that failed the schema is what needs debugging.
     */
    public function markAnalysisFailed(?string $rawLlmResponse = null): void
    {
        $this->assertAnalysisPending();

        $this->analysisStatus = LlmOperationStatus::Failed;
        $this->rawLlmResponse = $rawLlmResponse;
    }

    private function assertAnalysisPending(): void
    {
        if (LlmOperationStatus::Pending !== $this->analysisStatus) {
            throw CvAnalysisAlreadyFinished::withStatus($this->analysisStatus);
        }
    }
}
