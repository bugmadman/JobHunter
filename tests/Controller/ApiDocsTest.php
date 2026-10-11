<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiDocsTest extends WebTestCase
{
    #[TestWith(['/api/v1/docs', 'text/html'])]
    #[TestWith(['/api/v1/docs.jsonopenapi', 'application/vnd.openapi+json'])]
    #[TestWith(['/api/v1', 'application/ld+json'])]
    #[TestWith(['/api/v1/contexts/Entrypoint', 'application/ld+json'])]
    public function testPublicApiPathsAreReachableAnonymously(string $path, string $accept): void
    {
        $client = self::createClient();
        $client->request('GET', $path, server: ['HTTP_ACCEPT' => $accept]);

        self::assertResponseIsSuccessful();
    }
}
