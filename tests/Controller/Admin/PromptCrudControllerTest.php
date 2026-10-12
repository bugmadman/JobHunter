<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Domain\Prompt\PromptGenerationInProgress;
use App\Domain\Prompt\PromptServiceInterface;
use App\Domain\Prompt\PromptText;
use App\Entity\CvProfile;
use App\Entity\Prompt;
use App\Repository\CvProfileRepositoryInterface;
use App\Repository\PlatformRepositoryInterface;
use App\Repository\PromptRepositoryInterface;
use App\Tests\Controller\LogsInAsOwner;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PromptCrudControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const string INDEX_URL = '/admin/prompt';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    public function testIndexShowsThePlatformSourceStatusAndAnExcerpt(): void
    {
        $client = $this->loggedInClient();
        $prompt = $this->storePrompt($client, str_repeat('Like PHP roles. ', 20));

        $client->request('GET', self::INDEX_URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('td[data-column="platform.name"]', $prompt->getPlatform()->getName());
        self::assertSelectorTextSame('td[data-column="source"]', 'Generated from the CV');
        self::assertSelectorTextSame('td[data-column="generationStatus"]', 'Done');
        $excerpt = $client->getCrawler()->filter('td[data-column="text"]')->text();
        self::assertStringStartsWith('Like PHP roles.', $excerpt);
        self::assertLessThan(\strlen((string) $prompt->getText()), mb_strlen($excerpt));
        self::assertSelectorExists(\sprintf('a[href$="%s"]', self::editUrl($prompt)));
        self::assertSelectorNotExists(\sprintf('a[href$="%s/new"]', self::INDEX_URL));
        self::assertSelectorNotExists('.action-delete');
    }

    public function testIndexOffersNoEditingWhileGenerating(): void
    {
        $client = $this->loggedInClient();
        $prompt = $this->storePrompt($client, null);

        $client->request('GET', self::INDEX_URL);

        self::assertSelectorTextSame('td[data-column="generationStatus"]', 'In progress');
        self::assertSelectorNotExists(\sprintf('a[href$="%s"]', self::editUrl($prompt)));
    }

    #[TestWith(['GET', '/new'])]
    #[TestWith(['GET', '/%d'])]
    #[TestWith(['POST', '/%d/delete'])]
    public function testCreatingViewingAndDeletingAreDisabled(string $method, string $path): void
    {
        $client = $this->loggedInClient();
        $prompt = $this->storePrompt($client, 'Like PHP roles.');

        $client->request($method, self::INDEX_URL.\sprintf($path, $prompt->getId()), server: self::SAME_ORIGIN);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditingAnUnknownPromptIsNotFound(): void
    {
        $client = $this->loggedInClient();
        $client->request('GET', self::INDEX_URL.'/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheEditFormIsFilledAndSavesThroughTheService(): void
    {
        $client = $this->loggedInClient();
        $prompt = $this->storePrompt($client, 'Like PHP roles.');
        $this->mockPromptService($client)
            ->expects(self::once())
            ->method('edit')
            ->with(
                // The entity manager is reset between requests, so the edited prompt is a fresh instance
                self::callback(static fn (Prompt $edited): bool => $edited->getId() === $prompt->getId()),
                self::callback(static fn (PromptText $text): bool => 'Hand-written.' === $text->value),
            );

        $client->request('GET', self::editUrl($prompt));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Prompt for '.$prompt->getPlatform()->getName());
        self::assertSelectorTextSame('textarea[name="prompt[text]"]', 'Like PHP roles.');

        $this->submit($client, "  Hand-written.\n");

        self::assertResponseRedirects(self::INDEX_URL);
        $client->followRedirect();
        self::assertSelectorTextContains('#flash-messages', 'The prompt is saved.');
    }

    public function testTheEditFormOfAPromptWithoutTextIsEmpty(): void
    {
        $client = $this->loggedInClient();
        $prompt = $this->storePrompt($client, null);
        $prompt->markGenerationFailed();

        $client->request('GET', self::editUrl($prompt));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('textarea[name="prompt[text]"]', '');
    }

    #[TestWith(['', 'This value should not be blank.'])]
    #[TestWith([" \n\t", 'The prompt text must not be empty.'])]
    public function testRejectsABlankText(string $text, string $error): void
    {
        $client = $this->loggedInClient();
        $prompt = $this->storePrompt($client, 'Like PHP roles.');
        $this->mockPromptService($client)->expects(self::never())->method('edit');

        $client->request('GET', self::editUrl($prompt));
        $this->submit($client, $text);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', $error);
    }

    public function testShowsAnErrorWhenAGenerationStartedWhileEditing(): void
    {
        $client = $this->loggedInClient();
        $prompt = $this->storePrompt($client, 'Like PHP roles.');
        $this->mockPromptService($client)
            ->expects(self::once())
            ->method('edit')
            ->willThrowException(PromptGenerationInProgress::forPlatform('Upwork'));

        $client->request('GET', self::editUrl($prompt));
        $this->submit($client, 'Hand-written.');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="prompt"]', 'The prompt is being generated.');
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

    private function submit(KernelBrowser $client, string $text): void
    {
        $form = $client->getCrawler()->filter('form[name="prompt"]')->form();
        $client->submit($form, ['prompt[text]' => $text], self::SAME_ORIGIN);
    }

    private static function editUrl(Prompt $prompt): string
    {
        return \sprintf('%s/%d/edit', self::INDEX_URL, $prompt->getId());
    }

    /**
     * A null text leaves the generation pending.
     */
    private function storePrompt(KernelBrowser $client, ?string $text): Prompt
    {
        $container = $client->getContainer();

        $cvProfile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $cvProfile->markAnalysisDone(new CvAnalysis('Анализ', 'Analysis', null, null, null), [], '{}');
        $cvProfiles = $container->get(CvProfileRepositoryInterface::class);
        self::assertInstanceOf(CvProfileRepositoryInterface::class, $cvProfiles);
        $cvProfiles->save($cvProfile);

        $platforms = $container->get(PlatformRepositoryInterface::class);
        self::assertInstanceOf(PlatformRepositoryInterface::class, $platforms);
        $prompt = new Prompt($platforms->findAllOrderedByName()[0], $cvProfile);
        if (null !== $text) {
            $prompt->markGenerated(PromptText::fromString($text));
        }

        $prompts = $container->get(PromptRepositoryInterface::class);
        self::assertInstanceOf(PromptRepositoryInterface::class, $prompts);
        $prompts->save($prompt);

        return $prompt;
    }

    private function mockPromptService(KernelBrowser $client): PromptServiceInterface&MockObject
    {
        $promptService = $this->createMock(PromptServiceInterface::class);
        $client->getContainer()->set(PromptServiceInterface::class, $promptService);

        return $promptService;
    }
}
