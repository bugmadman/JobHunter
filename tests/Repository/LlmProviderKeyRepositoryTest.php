<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\Llm\EncryptedApiKey;
use App\Domain\Llm\LlmProvider;
use App\Entity\LlmProviderKey;
use App\Repository\LlmProviderKeyRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LlmProviderKeyRepositoryTest extends KernelTestCase
{
    public function testFindsNoConfiguredProvidersWithoutKeys(): void
    {
        self::assertSame([], $this->repository()->findConfiguredProviders());
    }

    public function testFindsTheProvidersThatHaveAKey(): void
    {
        $this->repository()->save(new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('ciphertext')));

        self::assertSame([LlmProvider::Gemini], $this->repository()->findConfiguredProviders());
    }

    public function testRemovesTheProviderKey(): void
    {
        $providerKey = new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('ciphertext'));
        $this->repository()->save($providerKey);

        $this->repository()->remove($providerKey);

        self::assertNull($this->repository()->findByProvider(LlmProvider::Gemini));
    }

    private function repository(): LlmProviderKeyRepository
    {
        $repository = self::getContainer()->get(LlmProviderKeyRepository::class);
        self::assertInstanceOf(LlmProviderKeyRepository::class, $repository);

        return $repository;
    }
}
