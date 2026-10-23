<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Tests\Controller\LogsInAsOwner;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('adminPages')]
    public function testAdminPagesLoadOnlyTheAdminEntrypoint(string $url): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("import 'admin';", $html);
        self::assertStringNotContainsString("import 'app';", $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminPages(): iterable
    {
        yield 'dashboard' => ['/admin'];
        yield 'crud form' => ['/admin/llm-provider-key/new'];
        yield 'custom page' => ['/admin/api-key'];
    }

    public function testDashboardRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }
}
