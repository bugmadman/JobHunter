<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Domain\User\IntakeApiKey;
use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use App\Tests\Controller\LogsInAsOwner;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiKeyControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site'];
    private const string WELL_FORMED_TOKEN = 'b8L2Vq9xR4mT7nK1wZ3cY6pA';

    public function testShowsThatNoKeyIsSet(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/admin/api-key');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Job posting intake API key');
        self::assertSelectorTextContains('body', 'No key yet.');
        self::assertSelectorTextContains('form[action="/admin/api-key/regenerate"] button', 'Generate a key');
        self::assertSelectorNotExists('[data-api-key]');
    }

    public function testTheBootstrapPromptHasAPlaceholderInsteadOfTheKey(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/admin/api-key');

        $prompt = $client->getCrawler()->filter('[data-bootstrap-prompt]')->text(normalizeWhitespace: false);
        self::assertStringContainsString('GET http://localhost/api/v1/scan-instructions', $prompt);
        self::assertStringContainsString('"Authorization: Bearer <API key>"', $prompt);
    }

    public function testRegeneratesTheKeyAndShowsItOnce(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $apiKey = IntakeApiKey::generate();
        $this->mockUserService($client)->expects(self::once())
            ->method('regenerateApiKey')
            ->with(self::isInstanceOf(User::class))
            ->willReturn($apiKey);

        $this->post($client, self::SAME_ORIGIN);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'),
        );
        self::assertSelectorTextSame('[data-api-key]', $apiKey->value());
        self::assertStringContainsString(
            '"Authorization: Bearer '.$apiKey->value().'"',
            $client->getCrawler()->filter('[data-bootstrap-prompt]')->text(normalizeWhitespace: false),
        );
    }

    public function testRejectsACrossSiteRequest(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $this->mockUserService($client)->expects(self::never())->method('regenerateApiKey');

        $this->post($client, self::CROSS_SITE);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/api-key');

        self::assertResponseRedirects('/login');
    }

    /**
     * @param array<string, string> $server
     */
    private function post(KernelBrowser $client, array $server): void
    {
        $client->request(
            'POST',
            '/admin/api-key/regenerate',
            ['_csrf_token' => self::WELL_FORMED_TOKEN],
            server: $server,
        );
    }

    private function mockUserService(KernelBrowser $client): UserServiceInterface&MockObject
    {
        $users = $this->createMock(UserServiceInterface::class);
        $client->getContainer()->set(UserServiceInterface::class, $users);

        return $users;
    }
}
