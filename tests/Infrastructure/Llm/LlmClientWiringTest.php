<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Llm;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\EncryptedApiKey;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;
use App\Entity\LlmProviderKey;
use App\Infrastructure\Llm\LlmRequest;
use App\Infrastructure\Security\ApiKeyEncryptorInterface;
use App\Repository\LlmModelSelectionRepositoryInterface;
use App\Repository\LlmProviderKeyRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LlmClientWiringTest extends KernelTestCase
{
    public function testTheGeminiProviderIsServedByTheGeminiClient(): void
    {
        $response = new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => 'Hello']]], 'finishReason' => 'STOP']],
        ], \JSON_THROW_ON_ERROR));

        $modelSelections = self::createStub(LlmModelSelectionRepositoryInterface::class);
        $modelSelections->method('findByTask')->willReturn(
            new LlmModelSelection(LlmTask::Matching, LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-flash')),
        );
        $providerKeys = self::createStub(LlmProviderKeyRepositoryInterface::class);
        $providerKeys->method('findByProvider')
            ->willReturn(new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('ciphertext')));
        $encryptor = self::createStub(ApiKeyEncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn(ApiKey::fromString('AIza-secret'));

        $container = self::getContainer();
        $container->set(HttpClientInterface::class, new MockHttpClient($response));
        $container->set(LlmModelSelectionRepositoryInterface::class, $modelSelections);
        $container->set(LlmProviderKeyRepositoryInterface::class, $providerKeys);
        $container->set(ApiKeyEncryptorInterface::class, $encryptor);

        $llmService = $container->get(LlmServiceInterface::class);
        self::assertInstanceOf(LlmServiceInterface::class, $llmService);

        self::assertSame('Hello', $llmService->generate(LlmTask::Matching, new LlmRequest('Say hello'))->text);
        self::assertStringStartsWith(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:',
            $response->getRequestUrl(),
        );
    }
}
