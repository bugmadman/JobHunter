<?php

declare(strict_types=1);

namespace App\Tests\Domain\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\EncryptedApiKey;
use App\Domain\Llm\LlmClientNotFound;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelNotSelected;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmService;
use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;
use App\Entity\LlmProviderKey;
use App\Infrastructure\Llm\LlmClientInterface;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmResponse;
use App\Infrastructure\Security\ApiKeyEncryptorInterface;
use App\Infrastructure\Security\InvalidEncryptionKey;
use App\Repository\LlmModelSelectionRepositoryInterface;
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

        self::service($providerKeys, $encryptor)->saveApiKey(LlmProvider::Gemini, $apiKey);
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

        self::service($providerKeys, $encryptor)->saveApiKey(LlmProvider::Gemini, $apiKey);
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

        $apiKey = self::service($providerKeys, $encryptor)->findApiKey(LlmProvider::Gemini);

        self::assertSame('AIza-secret', $apiKey?->value());
    }

    public function testFindsNoKeyForAnUnconfiguredProvider(): void
    {
        $providerKeys = self::createStub(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->willReturn(null);

        $encryptor = $this->createMock(ApiKeyEncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        self::assertNull(self::service($providerKeys, $encryptor)->findApiKey(LlmProvider::Gemini));
    }

    public function testSavesNothingWhenEncryptionFails(): void
    {
        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->expects(self::never())->method('save');

        $encryptor = self::createStub(ApiKeyEncryptorInterface::class);
        $encryptor->method('encrypt')->willThrowException(InvalidEncryptionKey::notSet());

        $this->expectException(InvalidEncryptionKey::class);

        self::service($providerKeys, $encryptor)->saveApiKey(LlmProvider::Gemini, ApiKey::fromString('AIza-secret'));
    }

    public function testDeletesTheProviderKey(): void
    {
        $providerKey = new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('ciphertext'));

        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->with(LlmProvider::Gemini)->willReturn($providerKey);
        $providerKeys->expects(self::once())->method('remove')->with($providerKey);

        self::service($providerKeys)->deleteApiKey(LlmProvider::Gemini);
    }

    public function testDeletingTheKeyOfAnUnconfiguredProviderDoesNothing(): void
    {
        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->willReturn(null);
        $providerKeys->expects(self::never())->method('remove');

        self::service($providerKeys)->deleteApiKey(LlmProvider::Gemini);
    }

    public function testSelectsAModelForANewTask(): void
    {
        $modelSelections = $this->createMock(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->with(LlmTask::Matching)->willReturn(null);
        $modelSelections->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (LlmModelSelection $saved): bool => LlmTask::Matching === $saved->getTask()
                && 'gemini-2.5-flash' === $saved->getModel()->value));

        self::service(modelSelections: $modelSelections)
            ->selectModel(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-flash'));
    }

    public function testChangesTheModelOfAConfiguredTask(): void
    {
        $selection = new LlmModelSelection(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('old-model'));

        $modelSelections = $this->createMock(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->with(LlmTask::Matching)->willReturn($selection);
        $modelSelections->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (LlmModelSelection $saved): bool => $saved === $selection
                && 'new-model' === $saved->getModel()->value));

        self::service(modelSelections: $modelSelections)
            ->selectModel(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('new-model'));
    }

    public function testDeletesTheModelSelection(): void
    {
        $selection = new LlmModelSelection(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('model'));

        $modelSelections = $this->createMock(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->with(LlmTask::Matching)->willReturn($selection);
        $modelSelections->expects(self::once())->method('remove')->with($selection);

        self::service(modelSelections: $modelSelections)->deleteModelSelection(LlmTask::Matching);
    }

    public function testDeletingAnUnconfiguredTaskDoesNothing(): void
    {
        $modelSelections = $this->createMock(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->willReturn(null);
        $modelSelections->expects(self::never())->method('remove');

        self::service(modelSelections: $modelSelections)->deleteModelSelection(LlmTask::Matching);
    }

    public function testGeneratesWithTheClientModelAndKeyOfTheTask(): void
    {
        $request = new LlmRequest('Say hello');
        $response = new LlmResponse('Hello');

        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::once())
            ->method('generate')
            ->with(
                $request,
                self::callback(static fn (LlmModel $model): bool => 'gemini-2.5-flash' === $model->value),
                self::callback(static fn (ApiKey $apiKey): bool => 'AIza-secret' === $apiKey->value()),
            )
            ->willReturn($response);

        $service = self::service(
            self::providerKeys(),
            self::encryptor(),
            self::modelSelections(),
            ['gemini' => $client],
        );

        self::assertSame($response, $service->generate(LlmTask::Matching, $request));
    }

    public function testFailsToGenerateWithoutASelectedModel(): void
    {
        $modelSelections = self::createStub(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->willReturn(null);

        $this->expectException(LlmModelNotSelected::class);
        $this->expectExceptionMessage('No model is selected for the "matching" LLM task.');

        self::service(self::providerKeys(), self::encryptor(), $modelSelections, $this->clientNeverCalled())
            ->generate(LlmTask::Matching, new LlmRequest('Say hello'));
    }

    public function testFailsToGenerateWithoutAClientForTheProvider(): void
    {
        $encryptor = $this->createMock(ApiKeyEncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        $this->expectException(LlmClientNotFound::class);
        $this->expectExceptionMessage('No LLM client is registered for the "gemini" provider.');

        $otherClient = $this->createMock(LlmClientInterface::class);
        $otherClient->expects(self::never())->method('generate');

        self::service(self::providerKeys(), $encryptor, self::modelSelections(), ['other' => $otherClient])
            ->generate(LlmTask::Matching, new LlmRequest('Say hello'));
    }

    public function testFailsToGenerateWithoutAKeyForTheProvider(): void
    {
        $providerKeys = self::createStub(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->willReturn(null);

        $this->expectException(ApiKeyNotSet::class);
        $this->expectExceptionMessage('No API key is set for the "gemini" LLM provider.');

        self::service($providerKeys, self::encryptor(), self::modelSelections(), $this->clientNeverCalled())
            ->generate(LlmTask::Matching, new LlmRequest('Say hello'));
    }

    /**
     * @param array<string, LlmClientInterface> $clients
     */
    private static function service(
        ?LlmProviderKeyRepositoryInterface $providerKeys = null,
        ?ApiKeyEncryptorInterface $encryptor = null,
        ?LlmModelSelectionRepositoryInterface $modelSelections = null,
        array $clients = [],
    ): LlmService {
        return new LlmService(
            $providerKeys ?? self::createStub(LlmProviderKeyRepositoryInterface::class),
            $encryptor ?? self::createStub(ApiKeyEncryptorInterface::class),
            $modelSelections ?? self::createStub(LlmModelSelectionRepositoryInterface::class),
            $clients,
        );
    }

    private static function providerKeys(): LlmProviderKeyRepositoryInterface
    {
        $providerKeys = self::createStub(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')
            ->willReturn(new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('ciphertext')));

        return $providerKeys;
    }

    private static function encryptor(): ApiKeyEncryptorInterface
    {
        $encryptor = self::createStub(ApiKeyEncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn(ApiKey::fromString('AIza-secret'));

        return $encryptor;
    }

    private static function modelSelections(): LlmModelSelectionRepositoryInterface
    {
        $modelSelections = self::createStub(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->willReturn(
            new LlmModelSelection(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-flash')),
        );

        return $modelSelections;
    }

    /**
     * @return array<string, LlmClientInterface>
     */
    private function clientNeverCalled(): array
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::never())->method('generate');

        return ['gemini' => $client];
    }
}
