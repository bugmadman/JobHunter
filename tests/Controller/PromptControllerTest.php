<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Cv\CvAnalysis;
use App\Domain\Cv\CvFile;
use App\Domain\Platform\PlatformAccessType;
use App\Domain\Prompt\AnalysedCvProfileNotFound;
use App\Domain\Prompt\PromptGenerationInProgress;
use App\Domain\Prompt\PromptServiceInterface;
use App\Domain\Prompt\PromptText;
use App\Entity\CvProfile;
use App\Entity\Platform;
use App\Entity\Prompt;
use App\Message\GeneratePrompt;
use App\Repository\PlatformRepositoryInterface;
use App\Repository\PromptRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PromptControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site'];
    private const string WELL_FORMED_TOKEN = 'b8L2Vq9xR4mT7nK1wZ3cY6pA';

    public function testListsThePlatformsWithLazyPromptFrames(): void
    {
        $client = $this->loggedInClient();
        $this->stubPlatforms($client)->method('findAllOrderedByName')->willReturn([$this->platform(3)]);

        $client->request('GET', '/prompts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main h2', 'Upwork');
        self::assertSelectorExists('turbo-frame#prompt-3[src="/prompts/3"]');
    }

    public function testShowsAGenerateButtonWhenThereIsNoPrompt(): void
    {
        $client = $this->loggedInClient();
        $this->stubPlatforms($client)->method('findById')->willReturnMap([[3, $this->platform(3)]]);
        $this->stubPrompts($client)->method('findByPlatform')->willReturn(null);

        $client->request('GET', '/prompts/3');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#prompt-3', 'No prompt yet.');
        self::assertSelectorTextContains(
            'turbo-frame#prompt-3 form[action="/prompts/3/generate"] button',
            'Generate prompt',
        );
        self::assertSelectorNotExists('[data-turbo-confirm]');
        self::assertSelectorNotExists('turbo-frame#prompt-3 a[href^="/admin/prompt"]');
    }

    public function testShowsAPendingGenerationInAFrameThatReloadsItself(): void
    {
        $client = $this->loggedInClient();
        $platform = $this->platform(3);
        $this->stubPlatforms($client)->method('findById')->willReturn($platform);
        $this->stubPrompts($client)->method('findByPlatform')->willReturn(new Prompt($platform, $this->analysedCv()));

        $client->request('GET', '/prompts/3');

        self::assertSelectorExists(
            'turbo-frame#prompt-3 [data-controller="frame-reload"][data-frame-reload-url-value="/prompts/3"]',
        );
        self::assertSelectorTextContains('turbo-frame#prompt-3', 'The prompt is being generated.');
        self::assertSelectorNotExists('turbo-frame#prompt-3 form');
        self::assertSelectorNotExists('turbo-frame#prompt-3 a[href^="/admin/prompt"]');
    }

    public function testShowsAGeneratedPromptWithARegenerateButton(): void
    {
        $client = $this->loggedInClient();
        $platform = $this->platform(3);
        $prompt = $this->prompt($platform, 11);
        $prompt->markGenerated(PromptText::fromString("Like PHP.\nDislike Java."));
        $this->stubPlatforms($client)->method('findById')->willReturn($platform);
        $this->stubPrompts($client)->method('findByPlatform')->willReturn($prompt);

        $crawler = $client->request('GET', '/prompts/3');

        self::assertSelectorNotExists('[data-controller="frame-reload"]');
        self::assertSelectorTextContains('turbo-frame#prompt-3', 'Generated from the CV');
        self::assertStringContainsString('Like PHP.<br>', $crawler->filter('turbo-frame#prompt-3')->html());
        self::assertSelectorTextContains('turbo-frame#prompt-3 form button', 'Regenerate prompt');
        self::assertSelectorNotExists('[data-turbo-confirm]');
        self::assertSelectorTextContains(
            'turbo-frame#prompt-3 a[href="/admin/prompt/11/edit"][data-turbo-frame="_top"]',
            'Edit',
        );
    }

    public function testAsksBeforeRegeneratingAnEditedPrompt(): void
    {
        $client = $this->loggedInClient();
        $platform = $this->platform(3);
        $prompt = $this->prompt($platform, 11);
        $prompt->markGenerationFailed();
        $prompt->edit(PromptText::fromString('Hand-written.'));
        $this->stubPlatforms($client)->method('findById')->willReturn($platform);
        $this->stubPrompts($client)->method('findByPlatform')->willReturn($prompt);

        $client->request('GET', '/prompts/3');

        self::assertSelectorTextContains('turbo-frame#prompt-3', 'Edited by hand');
        self::assertSelectorTextContains('turbo-frame#prompt-3 .alert-danger', 'The generation failed.');
        self::assertSelectorExists('form[data-turbo-confirm^="The prompt was edited by hand."]');
        self::assertSelectorExists('turbo-frame#prompt-3 a[href="/admin/prompt/11/edit"]');
    }

    public function testShowReturns404ForAnUnknownPlatform(): void
    {
        $client = $this->loggedInClient();
        $this->stubPlatforms($client)->method('findById')->willReturn(null);

        $client->request('GET', '/prompts/999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRequestsTheGenerationAndQueuesIt(): void
    {
        $client = $this->loggedInClient();
        $platform = $this->platform(3);
        $prompt = $this->prompt($platform, 11);
        $this->stubPlatforms($client)->method('findById')->willReturnMap([[3, $platform]]);
        $this->mockPromptService($client)
            ->expects(self::once())
            ->method('requestGeneration')
            ->with($platform)
            ->willReturn($prompt);

        $this->post($client, '/prompts/3/generate');

        self::assertResponseRedirects('/prompts/3', 303);
        self::assertEquals([new GeneratePrompt(11)], $this->queuedMessages($client));
    }

    public function testARepeatedRequestWhileGeneratingQueuesNothing(): void
    {
        $client = $this->loggedInClient();
        $this->stubPlatforms($client)->method('findById')->willReturn($this->platform(3));
        $this->mockPromptService($client)
            ->expects(self::once())
            ->method('requestGeneration')
            ->willThrowException(PromptGenerationInProgress::forPlatform('Upwork'));

        $this->post($client, '/prompts/3/generate');

        self::assertResponseRedirects('/prompts/3', 303);
        self::assertSame([], $this->queuedMessages($client));
    }

    public function testShowsAnErrorWithoutAnAnalysedCv(): void
    {
        $client = $this->loggedInClient();
        $this->stubPlatforms($client)->method('findById')->willReturn($this->platform(3));
        $this->stubPrompts($client)->method('findByPlatform')->willReturn(null);
        $this->mockPromptService($client)
            ->expects(self::once())
            ->method('requestGeneration')
            ->willThrowException(AnalysedCvProfileNotFound::create());

        $this->post($client, '/prompts/3/generate');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#prompt-3 .alert-danger', 'Upload a CV and wait for its analysis');
        self::assertSame([], $this->queuedMessages($client));
    }

    public function testGenerateReturns404ForAnUnknownPlatform(): void
    {
        $client = $this->loggedInClient();
        $this->stubPlatforms($client)->method('findById')->willReturn(null);
        $this->mockPromptService($client)->expects(self::never())->method('requestGeneration');

        $this->post($client, '/prompts/999/generate');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsACrossSiteRequest(): void
    {
        $client = $this->loggedInClient();
        $this->mockPromptService($client)->expects(self::never())->method('requestGeneration');

        $this->post($client, '/prompts/3/generate', self::CROSS_SITE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/prompts');

        self::assertResponseRedirects('/login');
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = self::createClient();
        // Keeps the mocked services across the requests of one test
        $client->disableReboot();
        $this->logInAsOwner($client);

        return $client;
    }

    /**
     * @param array<string, string> $server
     */
    private function post(KernelBrowser $client, string $url, array $server = self::SAME_ORIGIN): void
    {
        $client->request('POST', $url, ['_csrf_token' => self::WELL_FORMED_TOKEN], server: $server);
    }

    /**
     * @return list<object>
     */
    private function queuedMessages(KernelBrowser $client): array
    {
        $transport = $client->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(
            static fn (Envelope $envelope): object => $envelope->getMessage(),
            [...$transport->getSent()],
        ));
    }

    private function platform(int $id): Platform
    {
        $platform = new Platform('Upwork', PlatformAccessType::Browser);
        new \ReflectionProperty(Platform::class, 'id')->setValue($platform, $id);

        return $platform;
    }

    private function prompt(Platform $platform, int $id): Prompt
    {
        $prompt = new Prompt($platform, $this->analysedCv());
        new \ReflectionProperty(Prompt::class, 'id')->setValue($prompt, $id);

        return $prompt;
    }

    private function analysedCv(): CvProfile
    {
        $cvProfile = new CvProfile(CvFile::create('3f9a1c.pdf', 'My CV.pdf'));
        $cvProfile->markAnalysisDone(new CvAnalysis('Анализ', 'Analysis', null, null, null), [], '{}');

        return $cvProfile;
    }

    private function stubPlatforms(KernelBrowser $client): PlatformRepositoryInterface&Stub
    {
        $platforms = self::createStub(PlatformRepositoryInterface::class);
        $client->getContainer()->set(PlatformRepositoryInterface::class, $platforms);

        return $platforms;
    }

    private function stubPrompts(KernelBrowser $client): PromptRepositoryInterface&Stub
    {
        $prompts = self::createStub(PromptRepositoryInterface::class);
        $client->getContainer()->set(PromptRepositoryInterface::class, $prompts);

        return $prompts;
    }

    private function mockPromptService(KernelBrowser $client): PromptServiceInterface&MockObject
    {
        $promptService = $this->createMock(PromptServiceInterface::class);
        $client->getContainer()->set(PromptServiceInterface::class, $promptService);

        return $promptService;
    }
}
