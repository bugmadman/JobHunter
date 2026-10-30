<?php

declare(strict_types=1);

namespace App\Tests\Domain\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\EncryptedApiKey;
use App\Domain\Llm\LlmClientNotFound;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelList;
use App\Domain\Llm\LlmModelNotAvailable;
use App\Domain\Llm\LlmModelNotSelected;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmService;
use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;
use App\Entity\LlmProviderKey;
use App\Infrastructure\Llm\LlmClientInterface;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Llm\LlmResponse;
use App\Infrastructure\Security\ApiKeyEncryptorInterface;
use App\Infrastructure\Security\InvalidEncryptionKey;
use App\Repository\LlmModelSelectionRepositoryInterface;
use App\Repository\LlmProviderKeyRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Cache\CacheInterface;

final class LlmServiceTest extends TestCase
{
    private const int DAY = 24 * 60 * 60;

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

        self::service(self::providerKeys(), self::encryptor(), $modelSelections, self::listingClient())
            ->selectModel(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-flash'));
    }

    public function testChangesTheModelOfAConfiguredTask(): void
    {
        $selection = new LlmModelSelection(
            LlmTask::Matching,
            LlmProvider::Gemini,
            LlmModel::fromString('gemini-2.5-flash'),
        );

        $modelSelections = $this->createMock(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->with(LlmTask::Matching)->willReturn($selection);
        $modelSelections->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (LlmModelSelection $saved): bool => $saved === $selection
                && 'gemini-2.5-pro' === $saved->getModel()->value));

        self::service(self::providerKeys(), self::encryptor(), $modelSelections, self::listingClient())
            ->selectModel(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-pro'));
    }

    public function testRefusesAModelMissingFromTheProviderList(): void
    {
        $modelSelections = $this->createMock(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->expects(self::never())->method('save');

        $this->expectException(LlmModelNotAvailable::class);
        $this->expectExceptionMessage(
            'The "gemini-1.0-pro" model is not in the model list of the "gemini" LLM provider.',
        );

        self::service(self::providerKeys(), self::encryptor(), $modelSelections, self::listingClient())
            ->selectModel(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('gemini-1.0-pro'));
    }

    public function testSelectsNoModelWhenTheProviderListCannotBeLoaded(): void
    {
        $providerKeys = self::createStub(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->willReturn(null);
        $modelSelections = $this->createMock(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->expects(self::never())->method('save');

        $this->expectException(ApiKeyNotSet::class);

        self::service($providerKeys, self::encryptor(), $modelSelections, self::listingClient())
            ->selectModel(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-flash'));
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

    public function testAcceptsATaskWithASelectedModelAndAKeyWithoutDecryptingIt(): void
    {
        $providerKeys = $this->createMock(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->expects(self::once())
            ->method('findByProvider')
            ->with(LlmProvider::Gemini)
            ->willReturn(new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('ciphertext')));

        $encryptor = $this->createMock(ApiKeyEncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        $modelSelections = $this->createMock(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->expects(self::once())
            ->method('findByTask')
            ->with(LlmTask::CvAnalysis)
            ->willReturn(new LlmModelSelection(
                LlmTask::CvAnalysis,
                LlmProvider::Gemini,
                LlmModel::fromString('gemini-2.5-flash'),
            ));

        self::service($providerKeys, $encryptor, $modelSelections, $this->clientNeverCalled())
            ->assertConfigured(LlmTask::CvAnalysis);
    }

    public function testRefusesATaskWithoutASelectedModel(): void
    {
        $modelSelections = self::createStub(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->willReturn(null);

        try {
            self::service(self::providerKeys(), self::encryptor(), $modelSelections)
                ->assertConfigured(LlmTask::CvAnalysis);
            self::fail('Expected the task to be refused.');
        } catch (LlmModelNotSelected $e) {
            self::assertSame(LlmTask::CvAnalysis, $e->task);
        }
    }

    public function testRefusesATaskWithoutTheKeyOfTheSelectedProvider(): void
    {
        $providerKeys = self::createStub(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->willReturn(null);

        try {
            self::service($providerKeys, self::encryptor(), self::modelSelections())
                ->assertConfigured(LlmTask::Matching);
            self::fail('Expected the task to be refused.');
        } catch (ApiKeyNotSet $e) {
            self::assertSame(LlmProvider::Gemini, $e->provider);
        }
    }

    public function testListsTheModelsOfTheProviderWithItsKey(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::once())
            ->method('listModels')
            ->with(self::callback(static fn (ApiKey $apiKey): bool => 'AIza-secret' === $apiKey->value()))
            ->willReturn(self::modelList());

        $models = self::service(self::providerKeys(), self::encryptor(), clients: ['gemini' => $client])
            ->listModels(LlmProvider::Gemini);

        self::assertSame(['gemini-2.5-flash', 'gemini-2.5-pro'], self::names($models));
    }

    public function testServesTheModelListFromTheCacheForADay(): void
    {
        $clock = new MockClock();
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::once())->method('listModels')->willReturn(self::modelList());
        $service = self::service(
            self::providerKeys(),
            self::encryptor(),
            clients: ['gemini' => $client],
            cache: new ArrayAdapter(clock: $clock),
        );

        $service->listModels(LlmProvider::Gemini);
        $clock->sleep(self::DAY - 1);

        self::assertSame(
            ['gemini-2.5-flash', 'gemini-2.5-pro'],
            self::names($service->listModels(LlmProvider::Gemini)),
        );
    }

    public function testFetchesTheModelListAgainAfterADay(): void
    {
        $clock = new MockClock();
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::exactly(2))->method('listModels')->willReturn(self::modelList());
        $service = self::service(
            self::providerKeys(),
            self::encryptor(),
            clients: ['gemini' => $client],
            cache: new ArrayAdapter(clock: $clock),
        );

        $service->listModels(LlmProvider::Gemini);
        $clock->sleep(self::DAY + 1);
        $service->listModels(LlmProvider::Gemini);
    }

    public function testCachesOnlyTheModelNames(): void
    {
        $cache = new ArrayAdapter();
        $client = self::createStub(LlmClientInterface::class);
        $client->method('listModels')->willReturn(self::modelList());

        self::service(self::providerKeys(), self::encryptor(), clients: ['gemini' => $client], cache: $cache)
            ->listModels(LlmProvider::Gemini);

        self::assertSame(
            ['llm.model_list.gemini' => serialize(['gemini-2.5-flash', 'gemini-2.5-pro'])],
            $cache->getValues(),
        );
    }

    public function testDoesNotCacheAFailedModelListRequest(): void
    {
        $cache = new ArrayAdapter();
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::exactly(2))
            ->method('listModels')
            ->willReturnOnConsecutiveCalls(
                self::throwException(LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 503')),
                self::modelList(),
            );
        $service = self::service(
            self::providerKeys(),
            self::encryptor(),
            clients: ['gemini' => $client],
            cache: $cache,
        );

        try {
            $service->listModels(LlmProvider::Gemini);
            self::fail('The failed request was expected to throw.');
        } catch (LlmRequestFailed) {
            self::assertFalse($cache->hasItem('llm.model_list.gemini'));
        }

        self::assertSame(
            ['gemini-2.5-flash', 'gemini-2.5-pro'],
            self::names($service->listModels(LlmProvider::Gemini)),
        );
    }

    public function testDoesNotCacheAnEmptyModelList(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::exactly(2))->method('listModels')->willReturn(new LlmModelList());
        $service = self::service(self::providerKeys(), self::encryptor(), clients: ['gemini' => $client]);

        $service->listModels(LlmProvider::Gemini);

        self::assertCount(0, $service->listModels(LlmProvider::Gemini));
    }

    public function testFailsToListModelsWithoutAKeyForTheProvider(): void
    {
        $providerKeys = self::createStub(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')->willReturn(null);
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::never())->method('listModels');

        $this->expectException(ApiKeyNotSet::class);

        self::service($providerKeys, self::encryptor(), clients: ['gemini' => $client])
            ->listModels(LlmProvider::Gemini);
    }

    public function testFailsToListModelsWithoutAClientForTheProvider(): void
    {
        $encryptor = $this->createMock(ApiKeyEncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        $this->expectException(LlmClientNotFound::class);

        self::service(self::providerKeys(), $encryptor)->listModels(LlmProvider::Gemini);
    }

    public function testSavingTheKeyDropsTheCachedModelList(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::exactly(2))->method('listModels')->willReturn(self::modelList());
        $encryptor = self::createStub(ApiKeyEncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn(ApiKey::fromString('AIza-secret'));
        $encryptor->method('encrypt')->willReturn(new EncryptedApiKey('new-ciphertext'));
        $service = self::service(self::providerKeys(), $encryptor, clients: ['gemini' => $client]);

        $service->listModels(LlmProvider::Gemini);
        $service->saveApiKey(LlmProvider::Gemini, ApiKey::fromString('AIza-new'));
        $service->listModels(LlmProvider::Gemini);
    }

    public function testAFailedKeySaveKeepsTheCachedModelList(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::once())->method('listModels')->willReturn(self::modelList());
        $encryptor = self::createStub(ApiKeyEncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn(ApiKey::fromString('AIza-secret'));
        $encryptor->method('encrypt')->willThrowException(InvalidEncryptionKey::notSet());
        $service = self::service(self::providerKeys(), $encryptor, clients: ['gemini' => $client]);

        $service->listModels(LlmProvider::Gemini);

        try {
            $service->saveApiKey(LlmProvider::Gemini, ApiKey::fromString('AIza-new'));
            self::fail('The failed encryption was expected to throw.');
        } catch (InvalidEncryptionKey) {
            $service->listModels(LlmProvider::Gemini);
        }
    }

    public function testDeletingTheKeyDropsTheCachedModelList(): void
    {
        $cache = new ArrayAdapter();
        $client = self::createStub(LlmClientInterface::class);
        $client->method('listModels')->willReturn(self::modelList());
        $service = self::service(
            self::providerKeys(),
            self::encryptor(),
            clients: ['gemini' => $client],
            cache: $cache,
        );

        $service->listModels(LlmProvider::Gemini);
        $service->deleteApiKey(LlmProvider::Gemini);

        self::assertFalse($cache->hasItem('llm.model_list.gemini'));
    }

    public function testForgettingTheModelListReloadsItFromTheProvider(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects(self::exactly(2))->method('listModels')->willReturn(self::modelList());
        $service = self::service(self::providerKeys(), self::encryptor(), clients: ['gemini' => $client]);

        $service->listModels(LlmProvider::Gemini);
        $service->forgetModelList(LlmProvider::Gemini);
        $service->listModels(LlmProvider::Gemini);
    }

    /**
     * @param array<string, LlmClientInterface> $clients
     */
    private static function service(
        ?LlmProviderKeyRepositoryInterface $providerKeys = null,
        ?ApiKeyEncryptorInterface $encryptor = null,
        ?LlmModelSelectionRepositoryInterface $modelSelections = null,
        array $clients = [],
        ?CacheInterface $cache = null,
    ): LlmService {
        return new LlmService(
            $providerKeys ?? self::createStub(LlmProviderKeyRepositoryInterface::class),
            $encryptor ?? self::createStub(ApiKeyEncryptorInterface::class),
            $modelSelections ?? self::createStub(LlmModelSelectionRepositoryInterface::class),
            $clients,
            $cache ?? new ArrayAdapter(),
        );
    }

    /**
     * @return array<string, LlmClientInterface>
     */
    private static function listingClient(): array
    {
        $client = self::createStub(LlmClientInterface::class);
        $client->method('listModels')->willReturn(self::modelList());

        return ['gemini' => $client];
    }

    private static function modelList(): LlmModelList
    {
        return new LlmModelList(LlmModel::fromString('gemini-2.5-flash'), LlmModel::fromString('gemini-2.5-pro'));
    }

    /**
     * @return list<string>
     */
    private static function names(LlmModelList $models): array
    {
        return array_map(static fn (LlmModel $model): string => $model->value, iterator_to_array($models, false));
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
