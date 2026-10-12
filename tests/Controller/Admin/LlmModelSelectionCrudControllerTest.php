<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Llm\LlmModel;
use App\Domain\Llm\LlmProvider;
use App\Domain\Llm\LlmServiceInterface;
use App\Domain\Llm\LlmTask;
use App\Entity\LlmModelSelection;
use App\Repository\LlmModelSelectionRepositoryInterface;
use App\Tests\Controller\LogsInAsOwner;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    public function testSelectsTheModelThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $this->mockLlmService($client)
            ->expects(self::once())
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
            'llm_model_selection[model]' => ' gemini-2.5-flash-lite ',
        ]);

        self::assertResponseRedirects(self::INDEX_URL);
        $client->followRedirect();
        self::assertSelectorTextContains('#flash-messages', 'The model is saved.');
    }

    #[TestWith(['   ', 'This value should not be blank.'])]
    #[TestWith([
        'm-is-longer-than-a-hundred-characters-m-is-longer-than-a-hundred-characters-m-is-longer-than-a-hundred',
        'This value is too long.',
    ])]
    public function testRejectsAnInvalidModel(string $model, string $error): void
    {
        $client = $this->loggedInClient();
        $this->mockLlmService($client)->expects(self::never())->method('selectModel');

        $client->request('GET', self::NEW_URL);
        $this->submit($client, [
            'llm_model_selection[task]' => 'cv_analysis',
            'llm_model_selection[provider]' => 'gemini',
            'llm_model_selection[model]' => $model,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', $error);
    }

    public function testTheEditFormIsFilledAndChangesTheModelOfItsTask(): void
    {
        $client = $this->loggedInClient();
        $id = $this->storeSelection($client, LlmTask::Matching)->getId();
        $this->mockLlmService($client)
            ->expects(self::once())
            ->method('selectModel')
            ->with(
                LlmTask::Matching,
                LlmProvider::Gemini,
                self::callback(static fn (LlmModel $model): bool => 'gemini-2.5-pro' === $model->value),
            );

        $client->request('GET', self::INDEX_URL.'/'.$id.'/edit');
        self::assertSelectorNotExists('select[name="llm_model_selection[task]"]');
        self::assertInputValueSame('llm_model_selection[model]', 'gemini-2.5-flash');

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

    private function storeSelection(KernelBrowser $client, LlmTask $task): LlmModelSelection
    {
        $selection = new LlmModelSelection($task, LlmProvider::Gemini, LlmModel::fromString('gemini-2.5-flash'));
        $modelSelections = $client->getContainer()->get(LlmModelSelectionRepositoryInterface::class);
        self::assertInstanceOf(LlmModelSelectionRepositoryInterface::class, $modelSelections);
        $modelSelections->save($selection);

        return $selection;
    }

    private function mockLlmService(KernelBrowser $client): LlmServiceInterface&MockObject
    {
        $llmService = $this->createMock(LlmServiceInterface::class);
        $client->getContainer()->set(LlmServiceInterface::class, $llmService);

        return $llmService;
    }
}
