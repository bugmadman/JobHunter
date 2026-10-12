<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Platform\PlatformAccessType;
use App\Repository\PlatformRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlatformRepository::class)]
final class Platform
{
    public const int MAX_NAME_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: self::MAX_NAME_LENGTH, unique: true)]
        private string $name,
        #[ORM\Column(length: 32, enumType: PlatformAccessType::class)]
        private PlatformAccessType $accessType,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAccessType(): PlatformAccessType
    {
        return $this->accessType;
    }
}
