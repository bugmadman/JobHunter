<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Domain\JobPosting\JobPostingDetails;
use App\Domain\JobPosting\JobPostingServiceInterface;
use App\Entity\JobPosting;
use App\Entity\JobPostingStageLog;
use App\Entity\Platform;
use App\Tests\Security\IssuesIntakeApiKey;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class JobPostingIntakeProcessorTest extends WebTestCase
{
    use IssuesIntakeApiKey;

    private const string PATH = '/api/v1/job-postings';
    private const array POSTING = [
        'platform' => 'LinkedIn',
        'title' => ' PHP Developer ',
        'description' => 'Symfony, PostgreSQL.',
        'url' => 'https://www.linkedin.com/jobs/view/123/',
        'company' => 'Acme',
        'location' => ' ',
    ];

    public function testSavesTheRawPostingAndLogsItsArrival(): void
    {
        $client = self::createClient();

        $this->post($client, self::POSTING);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $body = $this->json($client);
        self::assertIsInt($body['id']);
        self::assertResponseHeaderSame('Location', '/api/v1/job-postings/'.$body['id']);
        self::assertSame(['id' => $body['id'], 'status' => 'raw', 'platform' => 'LinkedIn'], $body);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $posting = $entityManager->find(JobPosting::class, $body['id']);
        self::assertInstanceOf(JobPosting::class, $posting);
        self::assertSame('LinkedIn', $posting->getPlatform()->getName());
        self::assertSame('PHP Developer', $posting->getTitle());
        self::assertSame('Acme', $posting->getCompany());
        self::assertNull($posting->getLocation());
        $logs = $entityManager->getRepository(JobPostingStageLog::class)->findBy(['jobPosting' => $posting]);
        self::assertCount(1, $logs);
        self::assertNull($logs[0]->getFrom());
        self::assertSame('raw', $logs[0]->getTo()->value);
    }

    public function testPassesThePlatformAndTheDetailsToTheService(): void
    {
        $client = self::createClient();
        $service = $this->mockService($client);
        $service->expects($this->once())->method('create')->willReturnCallback(
            static function (Platform $platform, JobPostingDetails $details): JobPosting {
                self::assertSame('LinkedIn', $platform->getName());
                self::assertEquals(
                    JobPostingDetails::create(
                        'PHP Developer',
                        'Symfony, PostgreSQL.',
                        'https://www.linkedin.com/jobs/view/123/',
                        'Acme',
                    ),
                    $details,
                );
                $posting = new JobPosting($platform, $details, new \DateTimeImmutable('2026-10-12 09:30:00'));
                new \ReflectionProperty(JobPosting::class, 'id')->setValue($posting, 7);

                return $posting;
            },
        );

        $this->post($client, self::POSTING);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['id' => 7, 'status' => 'raw', 'platform' => 'LinkedIn'], $this->json($client));
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[TestWith([['platform' => ' '], 'platform'])]
    #[TestWith([['platform' => "LinkedIn\u{0000}junk"], 'platform'])]
    #[TestWith([['platform' => ['LinkedIn']], 'platform'])]
    #[TestWith([['title' => null], 'title'])]
    #[TestWith([['title' => 5], 'title'])]
    #[TestWith([['title' => "\u{00A0}"], 'title'])]
    #[TestWith([['description' => ''], 'description'])]
    #[TestWith([['company' => []], 'company'])]
    #[TestWith([['url' => 'ftp://example.com/job'], 'url'])]
    #[TestWith([['url' => 'www.linkedin.com/jobs/view/123/'], 'url'])]
    public function testRejectsAnInvalidField(array $changes, string $field): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $this->post($client, $changes + self::POSTING);

        $this->assertViolations($client, [$field]);
    }

    public function testRejectsATooLongLine(): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $this->post($client, ['location' => str_repeat('a', 256)] + self::POSTING);

        $this->assertViolations($client, ['location']);
    }

    public function testRejectsATooLongPlatformBeforeLookingItUp(): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $this->post($client, ['platform' => str_repeat('a', 101)] + self::POSTING);

        $this->assertViolations($client, ['platform']);
        self::assertStringNotContainsString('aaaa', (string) $client->getResponse()->getContent());
    }

    public function testRejectsAMissingField(): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');
        $posting = self::POSTING;
        unset($posting['description']);

        $this->post($client, $posting);

        $this->assertViolations($client, ['description']);
    }

    public function testRejectsAnUnknownPlatform(): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $this->post($client, ['platform' => 'Monster'] + self::POSTING);

        $this->assertViolations($client, ['platform']);
        self::assertSame('Unknown platform "Monster".', $this->violations($client)[0]['message']);
    }

    /**
     * Details the constraints let through but the domain refuses, reported together with an unknown platform.
     */
    #[TestWith(['url', 'https://www.linkedin.com@evil.example/job'])]
    #[TestWith(['description', "Sym\u{0000}fony."])]
    public function testRejectsDetailsTheDomainRefuses(string $field, string $value): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $this->post($client, ['platform' => 'Monster', $field => $value] + self::POSTING);

        $this->assertViolations($client, ['platform', $field]);
        self::assertSame('This value is not valid.', $this->violations($client)[1]['message']);
    }

    public function testRejectsANonObjectBodyAsMissingFields(): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $this->postRaw($client, 'application/json', '[]');

        $this->assertViolations($client, ['platform', 'title', 'description', 'url']);
    }

    #[TestWith(['{"platform":'])]
    #[TestWith([''])]
    #[TestWith(['"LinkedIn"'])]
    #[TestWith(['null'])]
    public function testRejectsABodyThatIsNotAJsonObject(string $body): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $this->postRaw($client, 'application/json', $body);

        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    #[TestWith(['text/plain'])]
    #[TestWith(['application/ld+json'])]
    public function testAcceptsOnlyPlainJson(string $contentType): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $this->postRaw($client, $contentType, json_encode(self::POSTING, \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(415);
    }

    public function testRejectsARequestWithoutAKey(): void
    {
        $client = self::createClient();
        $this->mockService($client)->expects($this->never())->method('create');

        $client->jsonRequest('POST', self::PATH, self::POSTING);

        self::assertResponseStatusCodeSame(401);
    }

    public function testReadingAPostingBackRequiresTheKey(): void
    {
        $client = self::createClient();

        $client->request('GET', self::PATH.'/1');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAPostingCanNotBeReadBack(): void
    {
        $client = self::createClient();
        $apiKey = $this->issueOwnerApiKey($client)->value();

        $client->request('GET', self::PATH.'/1', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$apiKey]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAcceptedRequestCountsAgainstTheRateLimit(): void
    {
        $client = self::createClient();
        // A reboot would drop the mocked service and the in-memory limiter state between the requests
        $client->disableReboot();
        $service = $this->mockService($client);
        $service->expects($this->exactly(3))->method('create')->willReturnCallback(
            static function (Platform $platform, JobPostingDetails $details): JobPosting {
                $posting = new JobPosting($platform, $details, new \DateTimeImmutable('2026-10-12 09:30:00'));
                new \ReflectionProperty(JobPosting::class, 'id')->setValue($posting, 7);

                return $posting;
            },
        );
        $apiKey = $this->issueOwnerApiKey($client)->value();

        for ($request = 1; $request <= 3; ++$request) {
            $this->post($client, self::POSTING, $apiKey);
            self::assertResponseStatusCodeSame(201);
        }
        $this->post($client, self::POSTING, $apiKey);

        self::assertResponseStatusCodeSame(429);
    }

    /**
     * @param array<string, mixed> $posting
     */
    private function post(KernelBrowser $client, array $posting, ?string $apiKey = null): void
    {
        $apiKey ??= $this->issueOwnerApiKey($client)->value();

        $client->jsonRequest('POST', self::PATH, $posting, ['HTTP_AUTHORIZATION' => 'Bearer '.$apiKey]);
    }

    private function postRaw(KernelBrowser $client, string $contentType, string $body): void
    {
        $client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => $contentType,
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->issueOwnerApiKey($client)->value(),
        ], content: $body);
    }

    private function mockService(KernelBrowser $client): JobPostingServiceInterface&MockObject
    {
        $service = $this->createMock(JobPostingServiceInterface::class);
        $client->getContainer()->set(JobPostingServiceInterface::class, $service);

        return $service;
    }

    /**
     * @param list<string> $fields
     */
    private function assertViolations(KernelBrowser $client, array $fields): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(
            $fields,
            array_map(static fn (array $violation): mixed => $violation['propertyPath'], $this->violations($client)),
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function violations(KernelBrowser $client): array
    {
        $violations = $this->json($client)['violations'] ?? null;
        self::assertIsArray($violations);
        self::assertTrue(array_is_list($violations));

        return array_map(static function (mixed $violation): array {
            self::assertIsArray($violation);

            return $violation;
        }, $violations);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        $json = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }
}
