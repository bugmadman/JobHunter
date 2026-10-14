<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\CoverLetter\CoverLetterGenerationInProgress;
use App\Domain\CoverLetter\CoverLetterServiceInterface;
use App\Domain\CoverLetter\CoverLetterText;
use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\CoverLetter;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Message\GenerateCoverLetter;
use App\Repository\CoverLetterRepositoryInterface;
use App\Repository\JobPostingRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CoverLetterControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site'];
    private const string WELL_FORMED_TOKEN = 'b8L2Vq9xR4mT7nK1wZ3cY6pA';

    public function testShowsAGenerateButtonWhenThereIsNoLetter(): void
    {
        $client = $this->loggedInClient();
        $this->stubPostings($client)->method('findById')->willReturnMap([[7, $this->posting(7)]]);
        $this->stubCoverLetters($client)->method('findLatestByJobPosting')->willReturn(null);

        $client->request('GET', '/postings/7/cover-letter');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#posting-cover-letter', 'No cover letter yet.');
        self::assertSelectorTextContains(
            'turbo-frame#posting-cover-letter form[action="/postings/7/cover-letter"] button',
            'Generate cover letter',
        );
    }

    public function testShowsAPendingLetterInAFrameThatReloadsItself(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->posting(7);
        $this->stubPostings($client)->method('findById')->willReturn($posting);
        $this->stubCoverLetters($client)->method('findLatestByJobPosting')->willReturn($this->coverLetter($posting));

        $client->request('GET', '/postings/7/cover-letter');

        self::assertSelectorExists(
            'turbo-frame#posting-cover-letter [data-controller="frame-reload"]'
            .'[data-frame-reload-url-value="/postings/7/cover-letter"]',
        );
        self::assertSelectorTextContains('turbo-frame#posting-cover-letter', 'The letter is being generated.');
        self::assertSelectorNotExists('turbo-frame#posting-cover-letter form');
    }

    public function testShowsTheGeneratedLetterWithANewVersionButton(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->posting(7);
        $coverLetter = $this->coverLetter($posting);
        $coverLetter->markGenerated(CoverLetterText::fromString("Dear team,\nI fit."));
        $this->stubPostings($client)->method('findById')->willReturn($posting);
        $this->stubCoverLetters($client)->method('findLatestByJobPosting')->willReturn($coverLetter);

        $crawler = $client->request('GET', '/postings/7/cover-letter');

        self::assertSelectorNotExists('[data-controller="frame-reload"]');
        self::assertStringContainsString('Dear team,<br>', $crawler->filter('#cover-letter-text')->html());
        self::assertSelectorTextContains('turbo-frame#posting-cover-letter form button', 'Generate a new version');
    }

    public function testShowsAFailedGenerationWithARetryButton(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->posting(7);
        $coverLetter = $this->coverLetter($posting);
        $coverLetter->markGenerationFailed();
        $this->stubPostings($client)->method('findById')->willReturn($posting);
        $this->stubCoverLetters($client)->method('findLatestByJobPosting')->willReturn($coverLetter);

        $client->request('GET', '/postings/7/cover-letter');

        self::assertSelectorTextContains('turbo-frame#posting-cover-letter .alert-danger', 'The generation failed.');
        self::assertSelectorNotExists('#cover-letter-text');
        self::assertSelectorExists('turbo-frame#posting-cover-letter form button');
    }

    public function testShowReturns404ForAnUnknownPosting(): void
    {
        $client = $this->loggedInClient();
        $this->stubPostings($client)->method('findById')->willReturn(null);

        $client->request('GET', '/postings/999/cover-letter');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRequestsTheGenerationAndQueuesIt(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->posting(7);
        $this->stubPostings($client)->method('findById')->willReturnMap([[7, $posting]]);
        $this->mockCoverLetterService($client)
            ->expects(self::once())
            ->method('requestGeneration')
            ->with($posting)
            ->willReturn($this->coverLetter($posting, 11));

        $this->post($client, '/postings/7/cover-letter');

        self::assertResponseRedirects('/postings/7/cover-letter', 303);
        self::assertEquals([new GenerateCoverLetter(11)], $this->queuedMessages($client));
    }

    public function testARepeatedRequestWhileGeneratingQueuesNothing(): void
    {
        $client = $this->loggedInClient();
        $this->stubPostings($client)->method('findById')->willReturn($this->posting(7));
        $this->mockCoverLetterService($client)
            ->expects(self::once())
            ->method('requestGeneration')
            ->willThrowException(CoverLetterGenerationInProgress::forJobPosting('Senior PHP developer'));

        $this->post($client, '/postings/7/cover-letter');

        self::assertResponseRedirects('/postings/7/cover-letter', 303);
        self::assertSame([], $this->queuedMessages($client));
    }

    public function testGenerateReturns404ForAnUnknownPosting(): void
    {
        $client = $this->loggedInClient();
        $this->stubPostings($client)->method('findById')->willReturn(null);
        $this->mockCoverLetterService($client)->expects(self::never())->method('requestGeneration');

        $this->post($client, '/postings/999/cover-letter');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsACrossSiteRequest(): void
    {
        $client = $this->loggedInClient();
        $this->mockCoverLetterService($client)->expects(self::never())->method('requestGeneration');

        $this->post($client, '/postings/7/cover-letter', self::CROSS_SITE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/postings/7/cover-letter');

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

    private function posting(int $id): JobPosting
    {
        $posting = new JobPosting(
            new Platform('Upwork', PlatformAccessType::Browser),
            JobPostingDetails::create('Senior PHP developer', 'Description.', 'https://example.com/'.$id),
            new \DateTimeImmutable('2026-10-14 10:00'),
        );
        new \ReflectionProperty(JobPosting::class, 'id')->setValue($posting, $id);

        return $posting;
    }

    private function coverLetter(JobPosting $posting, ?int $id = null): CoverLetter
    {
        $coverLetter = new CoverLetter($posting, new \DateTimeImmutable('2026-10-14 11:00'));
        if (null !== $id) {
            new \ReflectionProperty(CoverLetter::class, 'id')->setValue($coverLetter, $id);
        }

        return $coverLetter;
    }

    private function stubPostings(KernelBrowser $client): JobPostingRepositoryInterface&Stub
    {
        $repository = self::createStub(JobPostingRepositoryInterface::class);
        $client->getContainer()->set(JobPostingRepositoryInterface::class, $repository);

        return $repository;
    }

    private function stubCoverLetters(KernelBrowser $client): CoverLetterRepositoryInterface&Stub
    {
        $repository = self::createStub(CoverLetterRepositoryInterface::class);
        $client->getContainer()->set(CoverLetterRepositoryInterface::class, $repository);

        return $repository;
    }

    private function mockCoverLetterService(KernelBrowser $client): CoverLetterServiceInterface&MockObject
    {
        $service = $this->createMock(CoverLetterServiceInterface::class);
        $client->getContainer()->set(CoverLetterServiceInterface::class, $service);

        return $service;
    }
}
