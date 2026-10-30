<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Llm\ApiKeyNotSet;
use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmModelList;
use App\Domain\Llm\LlmModelNotAvailable;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;
use App\Infrastructure\Llm\LlmRequestFailed;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\InvalidEncryptionKey;
use App\Repository\LlmModelSelectionRepositoryInterface;
use App\Tests\Controller\LogsInAsOwner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class LlmModelSelectionCrudControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const string INDEX_URL = '/admin/llm-model-selection';
    private const string NEW_URL = '/admin/llm-model-selection/new';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    public function testIndexShowsTheSelectedModels(): void
    {
        $client = $this->loggedInClient();
        $this->storeSelection($client, LlmTask::Matching);

        $client->request('GET', self::INDEX_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('td[data-column="task"]', 'Matching');
        self::assertSelectorTextSame('td[data-column="provider"]', 'Gemini');
        self::assertSelectorTextSame('td[data-column="model.value"]', 'gemini-2.5-flash');
    }

    public function testTheNewFormOffersOnlyTasksWithoutAModel(): void
    {
        $client = $this->loggedInClient();
        $this->storeSelection($client, LlmTask::Matching);
        $this->stubLlmService($client)->method('listModels')->willReturn(self::modelList());

        $client->request('GET', self::NEW_URL);

        self::assertSelectorExists('select[name="llm_model_selection[task]"] option[value="cv_analysis"]');
        self::assertSelectorNotExists('select[name="llm_model_selection[task]"] option[value="matching"]');
    }

    public function testIndexOffersNoAddingOnceEveryTaskHasAModel(): void
    {
        $client = $this->loggedInClient();
        foreach (LlmTask::cases() as $task) {
            $this->storeSelection($client, $task);
        }

        $client->request('GET', self::INDEX_URL);

        self::assertSelectorNotExists(\sprintf('a[href$="%s"]', self::NEW_URL));
    }

    public function testTheNewFormOffersTheProviderModelsSortedByName(): void
    {
        $client = $this->loggedInClient();
        $this->mockLlmService($client)
            ->method('listModels')
            ->with(LlmProvider::Gemini)
            ->willReturn(self::modelList());

        $crawler = $client->request('GET', self::NEW_URL);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Choose a model', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro'],
            $crawler->filter('select[name="llm_model_selection[model]"] option')->each(
                static fn (Crawler $option): string => $option->text(),
            ),
        );
        self::assertSame(
            ['', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro'],
            $crawler->filter('select[name="llm_model_selection[model]"] option')->each(
                static fn (Crawler $option): string => (string) $option->attr('value'),
            ),
        );
    }

    public function testSelectsTheModelThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $llmService = $this->mockLlmService($client);
        $llmService->method('listModels')->willReturn(self::modelList());
        $llmService->expects(self::once())
            ->method('selectModel')
            ->with(
                LlmTask::CvAnalysis,
                LlmProvider::Gemini,
                self::callback(static fn (LlmModel $model): bool => 'gemini-2.5-flash-lite' === $model->value),
            );

        $client->request('GET', self::NEW_URL);
        $this->submit($client, [
            'llm_model_selection[task]' => 'cv_analysis',
            'llm_model_selection[provider]' => 'gemini',
            'llm_model_selection[model]' => 'gemini-2.5-flash-lite',
        ]);

        self::assertResponseRedirects(self::INDEX_URL);
        $client->followRedirect();
        self::assertSelectorTextContains('#flash-messages', 'The model is saved.');
    }

    #[TestWith(['', 'This value should not be blank.'])]
    #[TestWith(['gemini-1.0-pro', 'The selected choice is invalid.'])]
    public function testRejectsAModelOutsideTheList(string $model, string $error): void
    {
        $client = $this->loggedInClient();
        $llmService = $this->mockLlmService($client);
        $llmService->method('listModels')->willReturn(self::modelList());
        $llmService->expects(self::never())->method('selectModel');

        $client->request('GET', self::NEW_URL);
        $this->submit($client, [
            'llm_model_selection[task]' => 'cv_analysis',
            'llm_model_selection[provider]' => 'gemini',
            'llm_model_selection[model]' => $model,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', $error);
    }

    public function testShowsTheServiceRefusalOfAModelAsAFieldError(): void
    {
        $client = $this->loggedInClient();
        $llmService = $this->mockLlmService($client);
        $llmService->method('listModels')->willReturn(self::modelList());
        $llmService->expects(self::once())->method('selectModel')->willThrowException(
            LlmModelNotAvailable::forProvider(LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-pro')),
        );

        $client->request('GET', self::NEW_URL);
        $this->submit($client, [
            'llm_model_selection[task]' => 'cv_analysis',
            'llm_model_selection[provider]' => 'gemini',
            'llm_model_selection[model]' => 'gemini-2.5-pro',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains(
            '.invalid-feedback',
            "This model is not in the provider's model list. Choose another one.",
        );
    }

    #[TestWith([self::NEW_URL])]
    #[TestWith([self::INDEX_URL.'/%d/edit'])]
    public function testWithoutAKeyShowsAHintToAddItInsteadOfTheForm(string $url): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeSelection($client, LlmTask::Matching)->getId();
        $this->stubLlmService($client)
            ->method('listModels')
            ->willThrowException(ApiKeyNotSet::forProvider(LlmProvider::Gemini));

        $client->request('GET', \sprintf($url, $id));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="llm_model_selection"]');
        self::assertSelectorTextContains('.alert-warning', 'Add the Gemini key first');
        self::assertSelectorExists('a[href$="/admin/llm-provider-key/new"]');
    }

    /**
     * @return iterable<string, array{\Exception, string}>
     */
    public static function modelListFailures(): iterable
    {
        yield 'request failed' => [
            LlmRequestFailed::create(LlmProvider::Gemini, 'HTTP 400: API key not valid.'),
            'The model list could not be loaded: The gemini request failed: HTTP 400: API key not valid.',
        ];
        yield 'key not decrypted' => [
            ApiKeyDecryptionFailed::create(),
            'The model list could not be loaded: The API key cannot be decrypted',
        ];
        yield 'no encryption key' => [
            InvalidEncryptionKey::notSet(),
            'The model list could not be loaded: LLM_KEY_ENCRYPTION_KEY is not set',
        ];
    }

    #[DataProvider('modelListFailures')]
    public function testWhenTheListFailsShowsTheReasonInsteadOfTheForm(\Exception $failure, string $error): void
    {
        $client = $this->loggedInClient();
        $this->stubLlmService($client)->method('listModels')->willThrowException($failure);

        $client->request('GET', self::NEW_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="llm_model_selection"]');
        self::assertSelectorTextContains('.alert-danger', $error);
        self::assertSelectorNotExists('a[href$="/admin/llm-provider-key/new"]');
    }

    public function testTheEditFormIsFilledAndChangesTheModelOfItsTask(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeSelection($client, LlmTask::Matching)->getId();
        $llmService = $this->mockLlmService($client);
        $llmService->method('listModels')->willReturn(self::modelList());
        $llmService->expects(self::once())
            ->method('selectModel')
            ->with(
                LlmTask::Matching,
                LlmProvider::Gemini,
                self::callback(static fn (LlmModel $model): bool => 'gemini-2.5-pro' === $model->value),
            );

        $client->request('GET', self::INDEX_URL.'/'.$id.'/edit');
        self::assertSelectorNotExists('select[name="llm_model_selection[task]"]');
        self::assertSelectorExists(
            'select[name="llm_model_selection[model]"] option[value="gemini-2.5-flash"][selected]',
        );

        $this->submit($client, ['llm_model_selection[model]' => 'gemini-2.5-pro']);

        self::assertResponseRedirects(self::INDEX_URL);
    }

    public function testTheEditFormShowsASavedModelMissingFromTheListAsUnavailable(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeSelection($client, LlmTask::Matching)->getId();
        $this->stubLlmService($client)
            ->method('listModels')
            ->willReturn(new LlmModelList(LlmModel::fromString('gemini-2.5-pro')));

        $client->request('GET', self::INDEX_URL.'/'.$id.'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame(
            'select[name="llm_model_selection[model]"] option[value="gemini-2.5-flash"][selected]',
            'gemini-2.5-flash (unavailable)',
        );
        self::assertSelectorTextSame(
            'select[name="llm_model_selection[model]"] option[value="gemini-2.5-pro"]',
            'gemini-2.5-pro',
        );
    }

    public function testTheEditFormShowsNoUnavailableModelWhileTheSavedOneIsListed(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeSelection($client, LlmTask::Matching)->getId();
        $this->stubLlmService($client)->method('listModels')->willReturn(self::modelList());

        $crawler = $client->request('GET', self::INDEX_URL.'/'.$id.'/edit');

        self::assertSame(
            ['Choose a model', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro'],
            $crawler->filter('select[name="llm_model_selection[model]"] option')->each(
                static fn (Crawler $option): string => $option->text(),
            ),
        );
    }

    public function testResubmittingAnUnavailableSavedModelShowsTheServiceRefusal(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeSelection($client, LlmTask::Matching)->getId();
        $llmService = $this->mockLlmService($client);
        $llmService->method('listModels')->willReturn(new LlmModelList(LlmModel::fromString('gemini-2.5-pro')));
        $llmService->expects(self::once())
            ->method('selectModel')
            ->with(
                LlmTask::Matching,
                LlmProvider::Gemini,
                self::callback(static fn (LlmModel $model): bool => 'gemini-2.5-flash' === $model->value),
            )
            ->willThrowException(
                LlmModelNotAvailable::forProvider(LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-flash')),
            );

        $client->request('GET', self::INDEX_URL.'/'.$id.'/edit');
        $this->submit($client, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains(
            '.invalid-feedback',
            "This model is not in the provider's model list. Choose another one.",
        );
        self::assertSelectorExists(
            'select[name="llm_model_selection[model]"] option[value="gemini-2.5-flash"][selected]',
        );
    }

    public function testAnUnavailableSavedModelIsReplacedByOneFromTheList(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeSelection($client, LlmTask::Matching)->getId();
        $llmService = $this->mockLlmService($client);
        $llmService->method('listModels')->willReturn(new LlmModelList(LlmModel::fromString('gemini-2.5-pro')));
        $llmService->expects(self::once())
            ->method('selectModel')
            ->with(
                LlmTask::Matching,
                LlmProvider::Gemini,
                self::callback(static fn (LlmModel $model): bool => 'gemini-2.5-pro' === $model->value),
            );

        $client->request('GET', self::INDEX_URL.'/'.$id.'/edit');
        $this->submit($client, ['llm_model_selection[model]' => 'gemini-2.5-pro']);

        self::assertResponseRedirects(self::INDEX_URL);
    }

    public function testDeletesTheSelectionThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeSelection($client, LlmTask::Matching)->getId();
        $this->mockLlmService($client)
            ->expects(self::once())
            ->method('deleteModelSelection')
            ->with(LlmTask::Matching);

        $crawler = $client->request('GET', self::INDEX_URL);
        $token = $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value');
        $client->request('POST', self::INDEX_URL.'/'.$id.'/delete', ['token' => $token]);

        self::assertResponseRedirects(self::INDEX_URL);
    }

    #[TestWith([self::NEW_URL])]
    #[TestWith([self::INDEX_URL.'/%d/edit'])]
    public function testRefreshingDropsTheCachedListAndReturnsToTheForm(string $url): void
    {
        $client = $this->loggedInClient();
        $url = \sprintf($url, $this->storeSelection($client, LlmTask::Matching)->getId());
        $llmService = $this->mockLlmService($client);
        $llmService->method('listModels')->willReturn(self::modelList());
        $llmService->expects(self::once())->method('forgetModelList')->with(LlmProvider::Gemini);

        $client->request('GET', $url);
        $this->refreshModelList($client);

        self::assertResponseRedirects($url);
        $client->followRedirect();
        self::assertSelectorTextContains('#flash-messages', 'The model list is reloaded from the provider.');
    }

    public function testRefreshingRejectsACrossSiteRequest(): void
    {
        $client = $this->loggedInClient();
        $this->mockLlmService($client)->expects(self::never())->method('forgetModelList');

        $client->request(
            'POST',
            self::INDEX_URL.'/refresh-model-list',
            ['_csrf_token' => 'b8L2Vq9xR4mT7nK1wZ3cY6pA', 'provider' => 'gemini', 'entity_id' => ''],
            server: ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
        );

        self::assertResponseStatusCodeSame(403);
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

    /**
     * @param array<string, string> $fields
     */
    private function submit(KernelBrowser $client, array $fields): void
    {
        $form = $client->getCrawler()->filter('form[name="llm_model_selection"]')->form();
        $form->disableValidation();
        $client->submit($form, $fields, self::SAME_ORIGIN);
    }

    private function refreshModelList(KernelBrowser $client): void
    {
        $form = $client->getCrawler()->filter('form[action$="/refresh-model-list"]')->form();
        $client->submit($form, [], self::SAME_ORIGIN);
    }

    private function storeSelection(KernelBrowser $client, LlmTask $task): LlmModelSelection
    {
        $selection = new LlmModelSelection($task, LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-flash'));
        $modelSelections = $client->getContainer()->get(LlmModelSelectionRepositoryInterface::class);
        self::assertInstanceOf(LlmModelSelectionRepositoryInterface::class, $modelSelections);
        $modelSelections->save($selection);

        return $selection;
    }

    private static function modelList(): LlmModelList
    {
        return new LlmModelList(
            LlmModel::fromString('gemini-2.5-pro'),
            LlmModel::fromString('gemini-2.5-flash-lite'),
            LlmModel::fromString('gemini-2.5-flash'),
        );
    }

    private function stubLlmService(KernelBrowser $client): LlmServiceInterface&Stub
    {
        $llmService = self::createStub(LlmServiceInterface::class);
        $client->getContainer()->set(LlmServiceInterface::class, $llmService);

        return $llmService;
    }

    private function mockLlmService(KernelBrowser $client): LlmServiceInterface&MockObject
    {
        $llmService = $this->createMock(LlmServiceInterface::class);
        $client->getContainer()->set(LlmServiceInterface::class, $llmService);

        return $llmService;
    }
}
