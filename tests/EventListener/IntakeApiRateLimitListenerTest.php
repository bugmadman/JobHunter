<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The test limit is a burst of 3 requests, refilled at one per minute (config/packages/rate_limiter.yaml).
 */
final class IntakeApiRateLimitListenerTest extends WebTestCase
{
    private const int LIMIT = 3;
    private const string API_PATH = '/api/v1';

    public function testThrottlesARequestWithoutAKeyBeforeAuthentication(): void
    {
        $client = $this->createClientKeepingTheLimiterState();
        for ($request = 1; $request <= self::LIMIT; ++$request) {
            $this->requestApi($client);
            self::assertResponseStatusCodeSame(401);
        }

        $this->requestApi($client);

        $response = $client->getResponse();
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        $retryAfter = $response->headers->get('Retry-After');
        self::assertIsNumeric($retryAfter);
        self::assertGreaterThanOrEqual(59, (int) $retryAfter);
        self::assertLessThanOrEqual(60, (int) $retryAfter);
        self::assertSame(
            ['title' => 'Too many requests. Try again later.', 'status' => 429],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testTheDocsShareTheLimitOfTheApi(): void
    {
        $client = $this->createClientKeepingTheLimiterState();
        for ($request = 1; $request <= self::LIMIT; ++$request) {
            $client->request('GET', '/api/v1/docs', server: ['HTTP_ACCEPT' => 'text/html']);
            self::assertResponseIsSuccessful();
        }

        $this->requestApi($client);

        self::assertResponseStatusCodeSame(429);
    }

    public function testEachClientIpHasItsOwnLimit(): void
    {
        $client = $this->createClientKeepingTheLimiterState();
        for ($request = 1; $request <= self::LIMIT; ++$request) {
            $this->requestApi($client, ['REMOTE_ADDR' => '192.0.2.1']);
        }

        $this->requestApi($client, ['REMOTE_ADDR' => '192.0.2.2']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDoesNotLimitPathsOutsideTheApi(): void
    {
        $client = $this->createClientKeepingTheLimiterState();
        for ($request = 1; $request <= self::LIMIT + 1; ++$request) {
            $client->request('GET', '/login');
            self::assertResponseIsSuccessful();
        }
    }

    /**
     * Routing rejects an unknown path before the limiter, and its error page is rendered in a sub-request
     * of the same path, which must not count either.
     */
    public function testDoesNotCountAnErrorPageRenderedForAnApiPath(): void
    {
        $client = $this->createClientKeepingTheLimiterState();
        for ($request = 1; $request <= self::LIMIT + 1; ++$request) {
            $client->request('GET', self::API_PATH.'/unknown', server: ['HTTP_ACCEPT' => 'application/ld+json']);
            self::assertResponseStatusCodeSame(404);
        }

        $this->requestApi($client);

        self::assertResponseStatusCodeSame(401);
    }

    private function createClientKeepingTheLimiterState(): KernelBrowser
    {
        $client = self::createClient();
        // A reboot would rebuild the container and drop the in-memory limiter state between the requests
        $client->disableReboot();

        return $client;
    }

    /**
     * @param array<string, string> $server
     */
    private function requestApi(KernelBrowser $client, array $server = []): void
    {
        $client->request('GET', self::API_PATH, server: ['HTTP_ACCEPT' => 'application/ld+json'] + $server);
    }
}
