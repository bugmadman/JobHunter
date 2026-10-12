<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Shared\Email;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    public function __construct(Email $email, #[\SensitiveParameter] string $passwordHash)
    {
        $this->email = $email->value;
        $this->password = self::nonEmptyHash($passwordHash);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): Email
    {
        return Email::fromString($this->email);
    }

    public function getUserIdentifier(): string
    {
        return $this->getEmail()->value;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function changePasswordHash(#[\SensitiveParameter] string $passwordHash): void
    {
        $this->password = self::nonEmptyHash($passwordHash);
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    private static function nonEmptyHash(#[\SensitiveParameter] string $passwordHash): string
    {
        if ('' === $passwordHash) {
            throw new \InvalidArgumentException('The password hash must not be empty.');
        }

        return $passwordHash;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
