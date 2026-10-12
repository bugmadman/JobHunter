<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Cv\LanguageLevel;
use App\Domain\Cv\LanguageProficiency;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['cv_profile_id', 'language'])]
final class CvLanguage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2)]
    private string $language;

    #[ORM\Column(length: 16, enumType: LanguageLevel::class)]
    private LanguageLevel $level;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'languages')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private CvProfile $cvProfile,
        LanguageProficiency $proficiency,
    ) {
        $this->language = $proficiency->language;
        $this->level = $proficiency->level;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCvProfile(): CvProfile
    {
        return $this->cvProfile;
    }

    public function getProficiency(): LanguageProficiency
    {
        return LanguageProficiency::create($this->language, $this->level);
    }
}
