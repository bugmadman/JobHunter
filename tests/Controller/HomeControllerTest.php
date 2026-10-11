<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    public function testHomePageRendersOnTheBaseLayout(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.navbar-brand', 'JobHunter');
        self::assertSelectorTextContains('h1', 'Job postings');
    }

    public function testHomePageRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }
}
