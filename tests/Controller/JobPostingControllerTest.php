<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingVerdict;
use App\Domain\JobPosting\StatusReason;
use App\Domain\Platform\PlatformAccessType;
use App\Entity\JobPosting;
use App\Entity\Platform;
use App\Repository\JobPostingRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JobPostingControllerTest extends WebTestCase
{
    use LogsInAsOwner;

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
        self::assertSelectorTextContains('#posting-7 a[href="https://example.com/7"]', 'Senior PHP developer');
        self::assertSelectorTextContains('#posting-7', 'Upwork');
        self::assertSelectorTextContains('#posting-7', 'Acme · Remote');
        self::assertSelectorTextContains('#posting-7', 'Evaluated');
        self::assertSelectorTextContains('#posting-7 .text-bg-success', 'Like');
        self::assertSelectorTextContains('#posting-8', 'New');
        self::assertSelectorNotExists('#posting-8 .text-bg-success, #posting-8 .text-bg-danger');
    }

    public function testShowsAnEmptyList(): void
    {
        $client = $this->loggedInClient();
        $this->stubPostings($client, []);

        $client->request('GET', '/postings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No job postings yet.');
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
     * @param list<JobPosting> $postings
     */
    private function stubPostings(KernelBrowser $client, array $postings): void
    {
        $repository = self::createStub(JobPostingRepositoryInterface::class);
        $repository->method('findAllNewestFirst')->willReturn($postings);
        $client->getContainer()->set(JobPostingRepositoryInterface::class, $repository);
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
