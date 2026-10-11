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
    public function testOtherApiPathsRedirectAnonymousToLogin(string $path): void
    {
        $client = self::createClient();
        $client->request('GET', $path, server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseRedirects('/login');
    }
}
