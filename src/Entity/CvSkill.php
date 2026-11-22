<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Cv\SkillLevel;
use App\Domain\Skill\SkillName;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['cv_profile_id', 'name'])]
final class CvSkill
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: Skill::MAX_NAME_LENGTH)]
    private string $name;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'cvSkills')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private CvProfile $cvProfile,
        SkillName $name,
        #[ORM\Column(enumType: SkillLevel::class)]
        private SkillLevel $level,
    ) {
        $this->name = $name->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCvProfile(): CvProfile
    {
        return $this->cvProfile;
    }

    public function getName(): SkillName
    {
        return SkillName::fromString($this->name);
    }

    public function getLevel(): SkillLevel
    {
        return $this->level;
    }
}
