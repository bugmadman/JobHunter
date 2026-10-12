<?php

declare(strict_types=1);

namespace App\Tests\Domain\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\EncryptedApiKey;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmService;
use App\Entity\LlmProviderKey;
use App\Infrastructure\Security\ApiKeyEncryptorInterface;
use App\Infrastructure\Security\InvalidEncryptionKey;
use App\Repository\LlmProviderKeyRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class LlmServiceTest extends TestCase
{
    public function testSavesANewProviderKeyEncrypted(): void
    {
        $apiKey = ApiKey::fromString('AIza-secret');

        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->with(LlmProvider::Gemini)->willReturn(null);
        $providerKeys->expects(self::once())
            ->method('save')
            ->with(self::callback(
                static fn (LlmProviderKey $saved): bool => 'ciphertext' === $saved->getEncryptedApiKey()->value(),
            ));

        $encryptor = $this->createMock(ApiKeyEncryptorInterface::class);
        $encryptor->expects(self::once())
            ->method('encrypt')
            ->with($apiKey)
            ->willReturn(new EncryptedApiKey('ciphertext'));

        new LlmService($providerKeys, $encryptor)->saveApiKey(LlmProvider::Gemini, $apiKey);
    }

    public function testReplacesTheKeyOfAnExistingProviderKey(): void
    {
        $apiKey = ApiKey::fromString('AIza-new');
        $providerKey = new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('old-ciphertext'));

        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->with(LlmProvider::Gemini)->willReturn($providerKey);
        $providerKeys->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (LlmProviderKey $saved): bool => $saved === $providerKey
                && 'new-ciphertext' === $saved->getEncryptedApiKey()->value()));

        $encryptor = $this->createMock(ApiKeyEncryptorInterface::class);
        $encryptor->method('encrypt')->with($apiKey)->willReturn(new EncryptedApiKey('new-ciphertext'));

        new LlmService($providerKeys, $encryptor)->saveApiKey(LlmProvider::Gemini, $apiKey);
    }

    public function testFindsTheDecryptedKey(): void
    {
        $encrypted = new EncryptedApiKey('ciphertext');

        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')
            ->with(LlmProvider::Gemini)
            ->willReturn(new LlmProviderKey(LlmProvider::Gemini, $encrypted));

        $encryptor = $this->createMock(ApiKeyEncryptorInterface::class);
        $encryptor->method('decrypt')
            ->with(self::callback(static fn (EncryptedApiKey $key): bool => 'ciphertext' === $key->value()))
            ->willReturn(ApiKey::fromString('AIza-secret'));

        $apiKey = new LlmService($providerKeys, $encryptor)->findApiKey(LlmProvider::Gemini);

        self::assertSame('AIza-secret', $apiKey?->value());
    }

    public function testFindsNoKeyForAnUnconfiguredProvider(): void
    {
        $providerKeys = self::createStub(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->willReturn(null);

        $encryptor = $this->createMock(ApiKeyEncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        self::assertNull(new LlmService($providerKeys, $encryptor)->findApiKey(LlmProvider::Gemini));
    }

    public function testSavesNothingWhenEncryptionFails(): void
    {
        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->expects(self::never())->method('save');

        $encryptor = self::createStub(ApiKeyEncryptorInterface::class);
        $encryptor->method('encrypt')->willThrowException(InvalidEncryptionKey::notSet());

        $this->expectException(InvalidEncryptionKey::class);

        new LlmService($providerKeys, $encryptor)->saveApiKey(LlmProvider::Gemini, ApiKey::fromString('AIza-secret'));
    }

    public function testDeletesTheProviderKey(): void
    {
        $providerKey = new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('ciphertext'));

        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->with(LlmProvider::Gemini)->willReturn($providerKey);
        $providerKeys->expects(self::once())->method('remove')->with($providerKey);

        new LlmService($providerKeys, self::createStub(ApiKeyEncryptorInterface::class))
            ->deleteApiKey(LlmProvider::Gemini);
    }

    public function testDeletingTheKeyOfAnUnconfiguredProviderDoesNothing(): void
    {
        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->willReturn(null);
        $providerKeys->expects(self::never())->method('remove');

        new LlmService($providerKeys, self::createStub(ApiKeyEncryptorInterface::class))
            ->deleteApiKey(LlmProvider::Gemini);
    }
}
