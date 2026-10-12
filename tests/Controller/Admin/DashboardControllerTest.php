<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\Controller\LogsInAsOwner;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    public function testDashboardRenders(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Settings');
    }

    public function testDashboardRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }
}
