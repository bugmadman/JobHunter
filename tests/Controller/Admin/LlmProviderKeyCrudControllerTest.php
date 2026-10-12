<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\EncryptedApiKey;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Entity\LlmProviderKey;
use App\Repository\LlmProviderKeyRepositoryInterface;
use App\Tests\Controller\LogsInAsOwner;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LlmProviderKeyCrudControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const string INDEX_URL = '/admin/llm-provider-key';
    private const string NEW_URL = '/admin/llm-provider-key/new';
    private const string MASK = '••••••••';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site'];

    public function testIndexShowsTheProviderAndAMaskInsteadOfTheKey(): void
    {
        $client = $this->loggedInClient();
        $this->llmService($client)->saveApiKey(LlmProvider::Gemini, ApiKey::fromString('AIza-plaintext'));
        $ciphertext = $this->storedKey($client)->getEncryptedApiKey()->value();

        $client->request('GET', self::INDEX_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('td[data-column="provider"]', 'Gemini');
        self::assertSelectorTextSame('td[data-column="apiKey"]', self::MASK);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('AIza-plaintext', $html);
        self::assertStringNotContainsString($ciphertext, $html);
    }

    public function testIndexOffersToAddAKeyForAProviderWithoutOne(): void
    {
        $client = $this->loggedInClient();
        $client->request('GET', self::INDEX_URL);

        self::assertSelectorExists(\sprintf('a[href$="%s"]', self::NEW_URL));
    }

    public function testIndexOffersNoAddingOnceEveryProviderHasAKey(): void
    {
        $client = $this->loggedInClient();
        $this->storeKey($client);

        $client->request('GET', self::INDEX_URL);

        self::assertSelectorNotExists(\sprintf('a[href$="%s"]', self::NEW_URL));
    }

    public function testTheDetailPageIsDisabled(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeKey($client)->getId();

        $client->request('GET', self::INDEX_URL.'/'.$id);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheNewFormHasAnEmptyPasswordField(): void
    {
        $client = $this->loggedInClient();
        $client->request('GET', self::NEW_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="llm_provider_key[provider]"] option[value="gemini"]');
        $this->assertTheKeyFieldIsEmpty($client);
    }

    public function testCreatesTheKeyThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $this->mockLlmService($client)
            ->expects(self::once())
            ->method('saveApiKey')
            ->with(
                LlmProvider::Gemini,
                self::callback(static fn (ApiKey $apiKey): bool => 'AIza-new' === $apiKey->value()),
            );

        $client->request('GET', self::NEW_URL);
        $this->submit($client, ['llm_provider_key[provider]' => 'gemini', 'llm_provider_key[apiKey]' => 'AIza-new']);

        self::assertResponseRedirects(self::INDEX_URL);
        $client->followRedirect();
        self::assertSelectorTextContains('#flash-messages', 'The key is saved.');
        self::assertStringNotContainsString('AIza-new', (string) $client->getResponse()->getContent());
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function testRejectsABlankKeyOnCreate(string $apiKey): void
    {
        $client = $this->loggedInClient();
        $this->mockLlmService($client)->expects(self::never())->method('saveApiKey');

        $client->request('GET', self::NEW_URL);
        $this->submit($client, ['llm_provider_key[provider]' => 'gemini', 'llm_provider_key[apiKey]' => $apiKey]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'This value should not be blank.');
    }

    public function testRejectsACrossSiteSubmit(): void
    {
        $client = $this->loggedInClient();
        $this->mockLlmService($client)->expects(self::never())->method('saveApiKey');

        $client->request('GET', self::NEW_URL);
        $this->submit(
            $client,
            ['llm_provider_key[provider]' => 'gemini', 'llm_provider_key[apiKey]' => 'AIza-new'],
            self::CROSS_SITE,
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsAProviderThatGotAKeyWhileTheFormWasOpen(): void
    {
        $client = $this->loggedInClient();
        $client->request('GET', self::NEW_URL);
        $this->storeKey($client);

        $this->submit($client, ['llm_provider_key[provider]' => 'gemini', 'llm_provider_key[apiKey]' => 'AIza-second']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'The selected choice is invalid.');
        self::assertStringNotContainsString('AIza-second', (string) $client->getResponse()->getContent());
        self::assertSame('stored-ciphertext', $this->storedKey($client)->getEncryptedApiKey()->value());
    }

    public function testTheEditFormNeverShowsTheStoredKey(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeKey($client)->getId();

        $client->request('GET', self::editUrl($id));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Replace the Gemini key');
        self::assertSelectorNotExists('select[name="llm_provider_key[provider]"]');
        $this->assertTheKeyFieldIsEmpty($client);
        self::assertStringNotContainsString('stored-ciphertext', (string) $client->getResponse()->getContent());
    }

    public function testReplacesTheKeyThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeKey($client)->getId();
        $this->mockLlmService($client)
            ->expects(self::once())
            ->method('saveApiKey')
            ->with(
                LlmProvider::Gemini,
                self::callback(static fn (ApiKey $apiKey): bool => 'AIza-replaced' === $apiKey->value()),
            );

        $client->request('GET', self::editUrl($id));
        $this->submit($client, ['llm_provider_key[apiKey]' => 'AIza-replaced']);

        self::assertResponseRedirects(self::INDEX_URL);
    }

    public function testRejectsABlankKeyOnEdit(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeKey($client)->getId();
        $this->mockLlmService($client)->expects(self::never())->method('saveApiKey');

        $client->request('GET', self::editUrl($id));
        $this->submit($client, ['llm_provider_key[apiKey]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'This value should not be blank.');
    }

    public function testEditingAnUnknownKeyIsNotFound(): void
    {
        $client = $this->loggedInClient();
        $client->request('GET', self::editUrl(999999));

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletesTheKeyThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeKey($client)->getId();
        $this->mockLlmService($client)->expects(self::once())->method('deleteApiKey')->with(LlmProvider::Gemini);

        $crawler = $client->request('GET', self::INDEX_URL);
        $token = $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value');
        $client->request('POST', self::INDEX_URL.'/'.$id.'/delete', ['token' => $token]);

        self::assertResponseRedirects(self::INDEX_URL);
    }

    public function testRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', self::INDEX_URL);

        self::assertResponseRedirects('/login');
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = self::createClient();
        // Keeps the mocked service and the stored rows across the requests of one test
        $client->disableReboot();
        $this->logInAsOwner($client);

        return $client;
    }

    private static function editUrl(?int $id): string
    {
        return self::INDEX_URL.'/'.$id.'/edit';
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $server
     */
    private function submit(KernelBrowser $client, array $fields, array $server = self::SAME_ORIGIN): void
    {
        $form = $client->getCrawler()->filter('form[name="llm_provider_key"]')->form();
        $form->disableValidation();
        $client->submit($form, $fields, $server);
    }

    private function assertTheKeyFieldIsEmpty(KernelBrowser $client): void
    {
        $field = $client->getCrawler()->filter('input[name="llm_provider_key[apiKey]"]');
        self::assertSame('password', $field->attr('type'));
        self::assertSame('', (string) $field->attr('value'));
    }

    private function storeKey(KernelBrowser $client): LlmProviderKey
    {
        $providerKey = new LlmProviderKey(LlmProvider::Gemini, new EncryptedApiKey('stored-ciphertext'));
        $this->providerKeys($client)->save($providerKey);

        return $providerKey;
    }

    private function storedKey(KernelBrowser $client): LlmProviderKey
    {
        $providerKey = $this->providerKeys($client)->findByProvider(LlmProvider::Gemini);
        self::assertNotNull($providerKey);

        return $providerKey;
    }

    private function providerKeys(KernelBrowser $client): LlmProviderKeyRepositoryInterface
    {
        $providerKeys = $client->getContainer()->get(LlmProviderKeyRepositoryInterface::class);
        self::assertInstanceOf(LlmProviderKeyRepositoryInterface::class, $providerKeys);

        return $providerKeys;
    }

    private function llmService(KernelBrowser $client): LlmServiceInterface
    {
        $llmService = $client->getContainer()->get(LlmServiceInterface::class);
        self::assertInstanceOf(LlmServiceInterface::class, $llmService);

        return $llmService;
    }

    private function mockLlmService(KernelBrowser $client): LlmServiceInterface&MockObject
    {
        $llmService = $this->createMock(LlmServiceInterface::class);
        $client->getContainer()->set(LlmServiceInterface::class, $llmService);

        return $llmService;
    }
}
