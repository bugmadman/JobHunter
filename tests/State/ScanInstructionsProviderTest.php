<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\Domain\ScanInstruction\ScanInstructionText;
use App\Domain\User\IntakeApiKey;
use App\Entity\ScanInstruction;
use App\Repository\PlatformRepository;
use App\Repository\ScanInstructionRepository;
use App\Tests\Security\IssuesIntakeApiKey;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ScanInstructionsProviderTest extends WebTestCase
{
    use IssuesIntakeApiKey;

    private const string PATH = '/api/v1/scan-instructions';
    private const array INTAKE = [
        'method' => 'POST',
        'url' => 'http://localhost/api/v1/job-postings',
        'docsUrl' => 'http://localhost/api/v1/docs.jsonopenapi',
    ];

    public function testListsThePlatformsWithAnInstructionByNameWithTheIntakeEndpoint(): void
    {
        $client = self::createClient();
        $this->addInstruction($client, 'Upwork', 'Search for PHP.', '2026-10-12 09:30:00');
        $this->addInstruction($client, 'LinkedIn', 'Search for Symfony.', '2026-10-14 18:05:42');

        $this->get($client, $this->issueOwnerApiKey($client)->value());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(
            [
                'intake' => self::INTAKE,
                'platforms' => [
                    [
                        'platform' => 'LinkedIn',
                        'instruction' => 'Search for Symfony.',
                        'updatedAt' => '2026-10-14T18:05:42+00:00',
                    ],
                    [
                        'platform' => 'Upwork',
                        'instruction' => 'Search for PHP.',
                        'updatedAt' => '2026-10-12T09:30:00+00:00',
                    ],
                ],
            ],
            $this->json($client),
        );
    }

    public function testListsNoPlatformsWithoutInstructions(): void
    {
        $client = self::createClient();

        $this->get($client, $this->issueOwnerApiKey($client)->value());

        self::assertResponseIsSuccessful();
        self::assertSame(['intake' => self::INTAKE, 'platforms' => []], $this->json($client));
    }

    public function testRejectsARequestWithoutAKey(): void
    {
        $client = self::createClient();
        $this->issueOwnerApiKey($client);
        $this->addInstruction($client, 'Upwork', 'Search for PHP.', '2026-10-12 09:30:00');

        $this->get($client);

        $this->assertUnauthorized($client);
    }

    public function testRejectsAKeyThatIsNotTheOwners(): void
    {
        $client = self::createClient();
        $this->issueOwnerApiKey($client);
        $this->addInstruction($client, 'Upwork', 'Search for PHP.', '2026-10-12 09:30:00');

        $this->get($client, IntakeApiKey::generate()->value());

        $this->assertUnauthorized($client);
    }

    public function testAnAcceptedRequestCountsAgainstTheRateLimit(): void
    {
        $client = self::createClient();
        // A reboot would drop the in-memory limiter state between the requests
        $client->disableReboot();
        $apiKey = $this->issueOwnerApiKey($client)->value();

        for ($request = 1; $request <= 3; ++$request) {
            $this->get($client, $apiKey);
            self::assertResponseIsSuccessful();
        }
        $this->get($client, $apiKey);

        self::assertResponseStatusCodeSame(429);
    }

    private function addInstruction(KernelBrowser $client, string $platformName, string $text, string $updatedAt): void
    {
        $platforms = $client->getContainer()->get(PlatformRepository::class);
        self::assertInstanceOf(PlatformRepository::class, $platforms);
        $instructions = $client->getContainer()->get(ScanInstructionRepository::class);
        self::assertInstanceOf(ScanInstructionRepository::class, $instructions);
        $platform = $platforms->findByName($platformName);
        self::assertNotNull($platform);

        $instructions->save(new ScanInstruction(
            $platform,
            ScanInstructionText::fromString($text),
            new \DateTimeImmutable($updatedAt),
        ));
    }

    private function get(KernelBrowser $client, ?string $apiKey = null): void
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $apiKey) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$apiKey;
        }

        $client->request('GET', self::PATH, server: $server);
    }

    private function assertUnauthorized(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertSame(['title' => 'A valid API key is required.', 'status' => 401], $this->json($client));
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
