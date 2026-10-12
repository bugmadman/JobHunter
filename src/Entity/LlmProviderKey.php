<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Llm\EncryptedApiKey;
use App\Domain\Llm\LlmProvider;
use App\Repository\LlmProviderKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LlmProviderKeyRepository::class)]
final class LlmProviderKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedApiKey;

    public function __construct(
        #[ORM\Column(length: 32, unique: true, enumType: LlmProvider::class)]
        private LlmProvider $provider,
        EncryptedApiKey $encryptedApiKey,
    ) {
        $this->encryptedApiKey = $encryptedApiKey->value();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): LlmProvider
    {
        return $this->provider;
    }

    public function getEncryptedApiKey(): EncryptedApiKey
    {
        return new EncryptedApiKey($this->encryptedApiKey);
    }

    public function changeApiKey(EncryptedApiKey $encryptedApiKey): void
    {
        $this->encryptedApiKey = $encryptedApiKey->value();
    }
}
