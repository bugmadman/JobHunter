<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageRendersOnTheBaseLayout(): void
    {
        $client = self::createClient();
        $client->loginUser(new InMemoryUser('owner@example.com', 'owner', ['ROLE_USER']));
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
