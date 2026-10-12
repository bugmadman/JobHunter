<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Llm\ApiKey;
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
    private string $apiKey;

    public function __construct(
        #[ORM\Column(length: 32, unique: true, enumType: LlmProvider::class)]
        private LlmProvider $provider,
        ApiKey $apiKey,
    ) {
        $this->apiKey = $apiKey->value();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): LlmProvider
    {
        return $this->provider;
    }

    public function getApiKey(): ApiKey
    {
        return ApiKey::fromString($this->apiKey);
    }

    public function changeApiKey(ApiKey $apiKey): void
    {
        $this->apiKey = $apiKey->value();
    }
}
