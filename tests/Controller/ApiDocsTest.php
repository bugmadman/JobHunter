<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiDocsTest extends WebTestCase
{
    #[TestWith(['/api/v1/docs', 'text/html'])]
    #[TestWith(['/api/v1/docs.jsonopenapi', 'application/vnd.openapi+json'])]
    public function testDocsAreReachableAnonymously(string $path, string $accept): void
    {
        $client = self::createClient();
        $client->request('GET', $path, server: ['HTTP_ACCEPT' => $accept]);

        self::assertResponseIsSuccessful();
    }

    #[TestWith(['/api/v1'])]
    #[TestWith(['/api/v1/index.jsonld'])]
    #[TestWith(['/api/v1/contexts/Entrypoint'])]
    #[TestWith(['/api/v1/errors/404'])]
    #[TestWith(['/api/v1/validation_errors/1'])]
    #[TestWith(['/api/v1/.well-known/genid/1'])]
    public function testOtherApiPathsRequireTheApiKey(string $path): void
    {
        $client = self::createClient();
        $client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDocumentsOnlyTheIntakeAndTheScanInstructionsWithTheApiKeyAsABearerToken(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/docs.jsonopenapi', server: ['HTTP_ACCEPT' => 'application/vnd.openapi+json']);

        $docs = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($docs);
        self::assertIsArray($docs['paths']);
        self::assertSame(['/api/v1/job-postings', '/api/v1/scan-instructions'], array_keys($docs['paths']));
        self::assertSame([['ApiKey' => []]], $docs['security']);
        self::assertIsArray($docs['components']);
        self::assertIsArray($docs['components']['securitySchemes']);
        self::assertIsArray($docs['components']['securitySchemes']['ApiKey']);
        self::assertSame('http', $docs['components']['securitySchemes']['ApiKey']['type']);
        self::assertSame('bearer', $docs['components']['securitySchemes']['ApiKey']['scheme']);
    }
}
