<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Skill\SkillName;
use App\Repository\SkillRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SkillRepository::class)]
final class Skill
{
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH, unique: true)]
    private string $name;

    public function __construct(SkillName $name)
    {
        $this->name = $name->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
