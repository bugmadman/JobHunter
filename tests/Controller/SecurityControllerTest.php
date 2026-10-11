<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SecurityControllerTest extends WebTestCase
{
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    public function testLoginPageRendersTheForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_username"]');
        self::assertSelectorExists('form input[name="_password"]');
        self::assertSelectorExists('form input[name="_csrf_token"]');
    }

    public function testLoginPageRedirectsLoggedInUserHome(): void
    {
        $client = self::createClient();
        $client->loginUser(new InMemoryUser('owner@example.com', 'owner', ['ROLE_USER']));
        $client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testLoginWithValidCredentialsOpensTheHomePage(): void
    {
        $client = self::createClient();
        $this->submitLogin($client, 'owner@example.com', 'owner');

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action^="/logout"]');
    }

    public function testLoginWithWrongPasswordShowsAnError(): void
    {
        $client = self::createClient();
        $this->submitLogin($client, 'owner@example.com', 'wrong');

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Invalid credentials.');
    }

    public function testLoginWithInvalidCsrfTokenShowsAnError(): void
    {
        $client = self::createClient();
        $this->submitLogin($client, 'owner@example.com', 'owner', 'invalid');

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Invalid CSRF token.');
    }

    public function testLogoutFormLogsTheUserOut(): void
    {
        $client = self::createClient();
        $client->loginUser(new InMemoryUser('owner@example.com', 'owner', ['ROLE_USER']));
        $client->request('GET', '/');
        $client->submitForm('Log out', [], 'POST', self::SAME_ORIGIN);
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testLogoutByGetWithoutCsrfTokenKeepsTheUserLoggedIn(): void
    {
        $client = self::createClient();
        $client->loginUser(new InMemoryUser('owner@example.com', 'owner', ['ROLE_USER']));
        $client->request('GET', '/logout');

        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    private function submitLogin(
        KernelBrowser $client,
        string $email,
        string $password,
        ?string $csrfToken = null,
    ): void {
        $client->request('GET', '/login');
        $values = ['_username' => $email, '_password' => $password];
        if (null !== $csrfToken) {
            $values['_csrf_token'] = $csrfToken;
        }

        $client->submitForm('Log in', $values, 'POST', self::SAME_ORIGIN);
    }
}
