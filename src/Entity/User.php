<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Shared\Email;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
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

    public function __construct(Email $email, string $passwordHash)
    {
        if ('' === $passwordHash) {
            throw new \InvalidArgumentException('The password hash must not be empty.');
        }

        $this->email = $email->value;
        $this->password = $passwordHash;
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

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
