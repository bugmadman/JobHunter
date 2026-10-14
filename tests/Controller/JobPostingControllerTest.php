<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingListFilter;
use App\Domain\JobPosting\JobPostingNotEvaluated;
use App\Domain\JobPosting\JobPostingServiceInterface;
use App\Domain\JobPosting\JobPostingStatus;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Domain\JobPosting\StatusReason;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\JobPosting;
use App\Entity\JobPostingStageLog;
use App\Entity\Platform;
use App\Repository\JobPostingRepositoryInterface;
use App\Repository\PlatformRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JobPostingControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site'];
    private const string WELL_FORMED_TOKEN = 'b8L2Vq9xR4mT7nK1wZ3cY6pA';

    public function testListsThePostingsAsCards(): void
    {
        $client = $this->loggedInClient();
        $liked = $this->posting(7, 'Senior PHP developer', 'Acme', 'Remote');
        $liked->markFiltered(new \DateTimeImmutable('2026-10-14 10:01'));
        $liked->markEvaluated(
            JobPostingVerdict::Like,
            StatusReason::fromString('Symfony, remote.'),
            new \DateTimeImmutable('2026-10-14 10:02'),
        );
        $this->stubPostings($client, [$liked, $this->posting(8, 'Java developer')]);

        $client->request('GET', '/postings');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'main article.card');
        self::assertSelectorTextContains('#posting-7 a[href="/postings/7"]', 'Senior PHP developer');
        self::assertSelectorTextContains('#posting-7', 'Upwork');
        self::assertSelectorTextContains('#posting-7', 'Acme · Remote');
        self::assertSelectorTextContains('#posting-7', 'Evaluated');
        self::assertSelectorTextContains('#posting-7 .text-bg-success', 'Like');
        self::assertSelectorTextContains('#posting-8', 'New');
        self::assertSelectorNotExists('#posting-8 .text-bg-success, #posting-8 .text-bg-danger');
    }

    public function testListsTheOwnersVerdictOverTheLlms(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->evaluatedPosting(7, JobPostingVerdict::Like);
        $posting->overrideVerdict(JobPostingVerdict::Dislike);
        $this->stubPostings($client, [$posting]);

        $client->request('GET', '/postings');

        self::assertSelectorTextSame('#posting-7 .text-bg-danger', 'Dislike · manual');
        self::assertSelectorNotExists('#posting-7 .text-bg-success');
    }

    public function testShowsAnEmptyList(): void
    {
        $client = $this->loggedInClient();
        $this->stubPostings($client, []);

        $client->request('GET', '/postings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No job postings yet.');
    }

    public function testFiltersByTheQueryAndKeepsTheChoice(): void
    {
        $client = $this->loggedInClient();
        $repository = $this->createMock(JobPostingRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findNewestFirst')
            ->with(new JobPostingListFilter(3, JobPostingStatus::Evaluated, JobPostingVerdict::Dislike))
            ->willReturn([]);
        $client->getContainer()->set(JobPostingRepositoryInterface::class, $repository);
        $this->stubPlatforms($client);

        $client->request('GET', '/postings?platform=3&status=evaluated&verdict=dislike');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#filter-platform option[selected]', 'LinkedIn');
        self::assertSelectorTextContains('#filter-status option[selected]', 'Evaluated');
        self::assertSelectorTextContains('#filter-verdict option[selected]', 'Dislike');
        self::assertSelectorTextContains('main', 'No job postings match the filters.');
        self::assertSelectorExists('#posting-filters a[href="/postings"]');
    }

    public function testIgnoresUnknownFilterValues(): void
    {
        $client = $this->loggedInClient();
        $repository = $this->createMock(JobPostingRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findNewestFirst')
            ->with(new JobPostingListFilter())
            ->willReturn([]);
        $client->getContainer()->set(JobPostingRepositoryInterface::class, $repository);
        $this->stubPlatforms($client);

        $client->request('GET', '/postings?platform=abc&status=unknown&verdict=maybe');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#posting-filters option[selected]');
        self::assertSelectorNotExists('#posting-filters a');
        self::assertSelectorTextContains('main', 'No job postings yet.');
    }

    public function testShowsThePostingWithTheLlmReason(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->posting(7, 'Senior PHP developer', 'Acme', 'Remote');
        $posting->markFiltered(new \DateTimeImmutable('2026-10-14 10:01'));
        $posting->markEvaluated(
            JobPostingVerdict::Dislike,
            StatusReason::fromString('Onsite only.'),
            new \DateTimeImmutable('2026-10-14 10:02'),
        );
        $this->stubPosting($client, 7, $posting);

        $client->request('GET', '/postings/7');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Senior PHP developer');
        self::assertSelectorTextContains('#posting-7', 'Acme · Remote');
        self::assertSelectorTextContains('#posting-7 .text-bg-danger', 'Dislike');
        self::assertSelectorTextContains('#posting-reason', 'LLM verdict reason');
        self::assertSelectorTextContains('#posting-reason', 'Onsite only.');
        self::assertSelectorTextContains('#posting-description', 'Description.');
        self::assertSelectorExists('#posting-original[href="https://example.com/7"]');
    }

    public function testShowsTheVerdictButtonsWithTheLlmVerdictChosen(): void
    {
        $client = $this->loggedInClient();
        $this->stubPosting($client, 7, $this->evaluatedPosting(7, JobPostingVerdict::Like));

        $client->request('GET', '/postings/7');

        self::assertResponseIsSuccessful();
        $form = 'turbo-frame#posting-verdict form[method="post"][action="/postings/7/verdict"]';
        self::assertSelectorExists($form.' input[name="_csrf_token"][data-controller="csrf-protection"]');
        self::assertSelectorCount(2, $form.' button[name="verdict"]');
        self::assertSelectorTextSame($form.' button[value="like"][aria-pressed="true"]', 'Like');
        self::assertSelectorTextSame($form.' button[value="dislike"][aria-pressed="false"]', 'Dislike');
        self::assertSelectorTextSame('turbo-frame#posting-verdict .text-bg-success', 'Like');
        self::assertSelectorNotExists('#posting-llm-verdict');
    }

    public function testShowsTheOwnersVerdictWithTheLlmsBesideIt(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->evaluatedPosting(7, JobPostingVerdict::Like);
        $posting->overrideVerdict(JobPostingVerdict::Dislike);
        $this->stubPosting($client, 7, $posting);

        $client->request('GET', '/postings/7');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#posting-verdict-form button[value="dislike"][aria-pressed="true"]');
        self::assertSelectorExists('#posting-verdict-form button[value="like"][aria-pressed="false"]');
        self::assertSelectorTextSame('turbo-frame#posting-verdict .text-bg-danger', 'Dislike · manual');
        self::assertSelectorTextSame('#posting-llm-verdict', 'The LLM said: Like');
        self::assertSelectorTextContains('#posting-reason', 'LLM verdict reason');
    }

    public function testShowsNoVerdictButtonsBeforeTheLlmVerdict(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->posting(8, 'Java developer');
        $posting->markFiltered(new \DateTimeImmutable('2026-10-14 10:01'));
        $this->stubPosting($client, 8, $posting);

        $client->request('GET', '/postings/8');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#posting-verdict');
        self::assertSelectorNotExists('#posting-verdict-form');
    }

    public function testShowsTheStatusReasonOfARejectedPosting(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->posting(8, 'Java developer');
        $posting->markRejected(
            StatusReason::fromString('No matching skills.'),
            new \DateTimeImmutable('2026-10-14 10:01'),
        );
        $this->stubPosting($client, 8, $posting);

        $client->request('GET', '/postings/8');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#posting-reason', 'Status reason');
        self::assertSelectorTextContains('#posting-reason', 'No matching skills.');
    }

    public function testShowsNoReasonForANewPosting(): void
    {
        $client = $this->loggedInClient();
        $this->stubPosting($client, 8, $this->posting(8, 'Java developer'));

        $client->request('GET', '/postings/8');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#posting-reason');
    }

    public function testShowsThePipelineHistory(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->posting(8, 'Java developer');
        $this->stubPosting($client, 8, $posting, [
            new JobPostingStageLog(
                $posting,
                null,
                JobPostingStatus::Raw,
                null,
                new \DateTimeImmutable('2026-10-14 10:00'),
            ),
            new JobPostingStageLog(
                $posting,
                JobPostingStatus::Raw,
                JobPostingStatus::Rejected,
                StatusReason::fromString('No matching skills.'),
                new \DateTimeImmutable('2026-10-14 10:01'),
            ),
        ]);

        $client->request('GET', '/postings/8');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#posting-history h2', 'Pipeline history');
        self::assertSelectorCount(2, '#posting-history li');
        self::assertSelectorTextContains('#posting-history li:nth-child(1)', '2026-10-14 10:00 · New');
        self::assertSelectorTextContains(
            '#posting-history li:nth-child(2)',
            '2026-10-14 10:01 · New → Rejected by keywords',
        );
        self::assertSelectorTextContains('#posting-history li:nth-child(2)', 'No matching skills.');
    }

    public function testShowReturns404ForAnUnknownPosting(): void
    {
        $client = $this->loggedInClient();
        $this->stubPosting($client, 9, null);

        $client->request('GET', '/postings/9');

        self::assertResponseStatusCodeSame(404);
    }

    public function testOverridesTheVerdictAndRedirectsBackToTheCard(): void
    {
        $client = $this->loggedInClient();
        $posting = $this->evaluatedPosting(7, JobPostingVerdict::Like);
        $this->stubPosting($client, 7, $posting);
        $this->mockPostingService($client)
            ->expects(self::once())
            ->method('overrideVerdict')
            ->with($posting, JobPostingVerdict::Dislike);

        $this->postVerdict($client, '/postings/7/verdict', 'dislike');

        self::assertResponseRedirects('/postings/7', 303);
    }

    public function testRejectsAnUnknownVerdict(): void
    {
        $client = $this->loggedInClient();
        $this->mockPostingService($client)->expects(self::never())->method('overrideVerdict');

        $this->postVerdict($client, '/postings/7/verdict', 'maybe');

        self::assertResponseStatusCodeSame(400);
    }

    public function testOverrideOfAPostingWithoutAnLlmVerdictIsAConflict(): void
    {
        $client = $this->loggedInClient();
        $this->stubPosting($client, 8, $this->posting(8, 'Java developer'));
        $this->mockPostingService($client)
            ->expects(self::once())
            ->method('overrideVerdict')
            ->willThrowException(JobPostingNotEvaluated::withStatus(JobPostingStatus::Raw));

        $this->postVerdict($client, '/postings/8/verdict', 'like');

        self::assertResponseStatusCodeSame(409);
    }

    public function testOverrideReturns404ForAnUnknownPosting(): void
    {
        $client = $this->loggedInClient();
        $this->stubPosting($client, 9, null);
        $this->mockPostingService($client)->expects(self::never())->method('overrideVerdict');

        $this->postVerdict($client, '/postings/9/verdict', 'like');

        self::assertResponseStatusCodeSame(404);
    }

    public function testRejectsACrossSiteVerdictRequest(): void
    {
        $client = $this->loggedInClient();
        $this->mockPostingService($client)->expects(self::never())->method('overrideVerdict');

        $this->postVerdict($client, '/postings/7/verdict', 'like', self::CROSS_SITE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/postings');

        self::assertResponseRedirects('/login');
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = self::createClient();
        $this->logInAsOwner($client);

        return $client;
    }

    /**
     * @param array<string, string> $server
     */
    private function postVerdict(
        KernelBrowser $client,
        string $url,
        string $verdict,
        array $server = self::SAME_ORIGIN,
    ): void {
        $client->request(
            'POST',
            $url,
            ['_csrf_token' => self::WELL_FORMED_TOKEN, 'verdict' => $verdict],
            server: $server,
        );
    }

    private function mockPostingService(KernelBrowser $client): JobPostingServiceInterface&MockObject
    {
        $service = $this->createMock(JobPostingServiceInterface::class);
        $client->getContainer()->set(JobPostingServiceInterface::class, $service);

        return $service;
    }

    /**
     * @param list<JobPosting> $postings
     */
    private function stubPostings(KernelBrowser $client, array $postings): void
    {
        $repository = self::createStub(JobPostingRepositoryInterface::class);
        $repository->method('findNewestFirst')->willReturn($postings);
        $client->getContainer()->set(JobPostingRepositoryInterface::class, $repository);
        $this->stubPlatforms($client);
    }

    /**
     * @param list<JobPostingStageLog> $history
     */
    private function stubPosting(KernelBrowser $client, int $id, ?JobPosting $posting, array $history = []): void
    {
        $repository = $this->createMock(JobPostingRepositoryInterface::class);
        $repository->expects(self::once())->method('findById')->with($id)->willReturn($posting);
        $repository->method('findStageLogsOldestFirst')->willReturn($history);
        $client->getContainer()->set(JobPostingRepositoryInterface::class, $repository);
    }

    private function stubPlatforms(KernelBrowser $client): void
    {
        $linkedIn = new Platform('LinkedIn', PlatformAccessType::Browser);
        new \ReflectionProperty(Platform::class, 'id')->setValue($linkedIn, 3);
        $upwork = new Platform('Upwork', PlatformAccessType::Browser);
        new \ReflectionProperty(Platform::class, 'id')->setValue($upwork, 5);

        $platforms = self::createStub(PlatformRepositoryInterface::class);
        $platforms->method('findAllOrderedByName')->willReturn([$linkedIn, $upwork]);
        $client->getContainer()->set(PlatformRepositoryInterface::class, $platforms);
    }

    private function evaluatedPosting(int $id, JobPostingVerdict $verdict): JobPosting
    {
        $posting = $this->posting($id, 'Senior PHP developer');
        $posting->markFiltered(new \DateTimeImmutable('2026-10-14 10:01'));
        $posting->markEvaluated(
            $verdict,
            StatusReason::fromString('Fits.'),
            new \DateTimeImmutable('2026-10-14 10:02'),
        );

        return $posting;
    }

    private function posting(int $id, string $title, ?string $company = null, ?string $location = null): JobPosting
    {
        $posting = new JobPosting(
            new Platform('Upwork', PlatformAccessType::Browser),
            JobPostingDetails::create($title, 'Description.', 'https://example.com/'.$id, $company, $location),
            new \DateTimeImmutable('2026-10-14 10:00'),
        );
        new \ReflectionProperty(JobPosting::class, 'id')->setValue($posting, $id);

        return $posting;
    }
}
