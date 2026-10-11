<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\DataFixtures\AppFixtures;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    use LogsInAsOwner;

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
        $this->logInAsOwner($client);
        $client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    #[TestWith([AppFixtures::OWNER_EMAIL])]
    #[TestWith(['Owner@Example.COM'])]
    public function testLoginWithValidCredentialsOpensTheHomePage(string $email): void
    {
        $client = self::createClient();
        $this->submitLogin($client, $email, AppFixtures::OWNER_PASSWORD);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action^="/logout"]');
    }

    #[TestWith([AppFixtures::OWNER_EMAIL, 'wrong'])]
    #[TestWith(['nobody@example.com', AppFixtures::OWNER_PASSWORD])]
    public function testLoginWithWrongCredentialsShowsAnError(string $email, string $password): void
    {
        $client = self::createClient();
        $this->submitLogin($client, $email, $password);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Invalid credentials.');
        $client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    public function testLoginWithInvalidCsrfTokenShowsAnError(): void
    {
        $client = self::createClient();
        $this->submitLogin($client, AppFixtures::OWNER_EMAIL, AppFixtures::OWNER_PASSWORD, 'invalid');

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Invalid CSRF token.');
    }

    public function testLogoutFormLogsTheUserOut(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/');
        $client->submitForm('Log out', [], 'POST', self::SAME_ORIGIN);
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testLogoutByGetWithoutCsrfTokenKeepsTheUserLoggedIn(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
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
