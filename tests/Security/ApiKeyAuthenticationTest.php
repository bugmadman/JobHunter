<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\DataFixtures\AppFixtures;
use App\Domain\User\IntakeApiKey;
use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Controller\LogsInAsOwner;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiKeyAuthenticationTest extends WebTestCase
{
    use LogsInAsOwner;

    private const string API_PATH = '/api/v1';

    public function testAcceptsTheOwnersKey(): void
    {
        $client = self::createClient();
        $apiKey = $this->issueOwnerApiKey($client);

        $this->requestApi($client, ['HTTP_AUTHORIZATION' => 'Bearer '.$apiKey->value()]);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function testRejectsARequestWithoutAKey(): void
    {
        $client = self::createClient();
        $this->issueOwnerApiKey($client);

        $this->requestApi($client);

        $this->assertUnauthorized($client);
    }

    public function testRejectsAWellFormedKeyThatIsNotTheOwners(): void
    {
        $client = self::createClient();
        $this->issueOwnerApiKey($client);

        $this->requestApi($client, ['HTTP_AUTHORIZATION' => 'Bearer '.IntakeApiKey::generate()->value()]);

        $this->assertUnauthorized($client);
    }

    #[TestWith(['upper-case'])]
    #[TestWith(['too-long'])]
    #[TestWith(['too-short'])]
    #[TestWith(['basic-scheme'])]
    #[TestWith(['custom-header'])]
    public function testRejectsAMalformedKey(string $case): void
    {
        $client = self::createClient();
        $key = $this->issueOwnerApiKey($client)->value();

        $this->requestApi($client, match ($case) {
            'upper-case' => ['HTTP_AUTHORIZATION' => 'Bearer '.strtoupper($key)],
            'too-long' => ['HTTP_AUTHORIZATION' => 'Bearer '.$key.'0'],
            'too-short' => ['HTTP_AUTHORIZATION' => 'Bearer '.substr($key, 1)],
            'basic-scheme' => ['HTTP_AUTHORIZATION' => 'Basic '.$key],
            'custom-header' => ['HTTP_X_API_KEY' => $key],
            default => throw new \LogicException($case),
        });

        $this->assertUnauthorized($client);
    }

    public function testIgnoresTheWebSession(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $this->requestApi($client);

        $this->assertUnauthorized($client);
    }

    /**
     * @param array<string, string> $server
     */
    private function requestApi(KernelBrowser $client, array $server = []): void
    {
        $client->request('GET', self::API_PATH, server: ['HTTP_ACCEPT' => 'application/ld+json'] + $server);
    }

    private function issueOwnerApiKey(KernelBrowser $client): IntakeApiKey
    {
        $container = $client->getContainer();
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $owner = $users->loadUserByIdentifier(AppFixtures::OWNER_EMAIL);
        self::assertInstanceOf(User::class, $owner);
        $service = $container->get(UserServiceInterface::class);
        self::assertInstanceOf(UserServiceInterface::class, $service);

        return $service->regenerateApiKey($owner);
    }

    private function assertUnauthorized(KernelBrowser $client): void
    {
        $response = $client->getResponse();

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame(
            ['title' => 'A valid API key is required.', 'status' => 401],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
