<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\DataFixtures\AppFixtures;
use App\Infrastructure\Security\PasswordHasherInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site'];
    private const string WELL_FORMED_TOKEN = 'b8L2Vq9xR4mT7nK1wZ3cY6pA';
    private const string THROTTLED = 'Too many failed login attempts, please try again in 15 minutes.';

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

    public function testLoginIsThrottledAfterFiveFailedAttemptsEvenWithTheRightPassword(): void
    {
        $client = self::createClient();
        // A reboot would rebuild the container and drop the in-memory limiter state between the attempts
        $client->disableReboot();
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->submitLogin($client, AppFixtures::OWNER_EMAIL, 'wrong');
            $client->followRedirect();
            self::assertSelectorTextContains('.alert-danger', 'Invalid credentials.');
        }

        $this->submitLogin($client, AppFixtures::OWNER_EMAIL, AppFixtures::OWNER_PASSWORD);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', self::THROTTLED);
        $client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }

    #[TestWith(['nobody@example.com', 1])]
    #[TestWith([AppFixtures::OWNER_EMAIL, 0])]
    public function testOnlyAnUnknownEmailVerifiesThePasswordAgainstTheDummyHash(
        string $email,
        int $dummyVerifications,
    ): void {
        $client = self::createClient();
        $client->disableReboot();
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::exactly($dummyVerifications))->method('verify')->with(self::anything(), 'wrong');
        self::getContainer()->set(PasswordHasherInterface::class, $hasher);

        $this->submitLogin($client, $email, 'wrong');

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

    public function testSameOriginLoginWithWellFormedTokenOpensTheHomePage(): void
    {
        $client = self::createClient();
        $this->submitLogin($client, AppFixtures::OWNER_EMAIL, AppFixtures::OWNER_PASSWORD, self::WELL_FORMED_TOKEN);

        self::assertResponseRedirects('/');
    }

    public function testCrossSiteLoginWithWellFormedTokenShowsAnError(): void
    {
        $client = self::createClient();
        $this->submitLogin(
            $client,
            AppFixtures::OWNER_EMAIL,
            AppFixtures::OWNER_PASSWORD,
            self::WELL_FORMED_TOKEN,
            self::CROSS_SITE,
        );

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Invalid CSRF token.');
        $client->request('GET', '/');
        self::assertResponseRedirects('/login');
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

    public function testSameOriginLogoutWithWellFormedTokenLogsTheUserOut(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/');
        $client->submitForm('Log out', ['_csrf_token' => self::WELL_FORMED_TOKEN], 'POST', self::SAME_ORIGIN);
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testCrossSiteLogoutWithWellFormedTokenKeepsTheUserLoggedIn(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/');
        $client->submitForm('Log out', ['_csrf_token' => self::WELL_FORMED_TOKEN], 'POST', self::CROSS_SITE);

        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $server
     */
    private function submitLogin(
        KernelBrowser $client,
        string $email,
        string $password,
        ?string $csrfToken = null,
        array $server = self::SAME_ORIGIN,
    ): void {
        $client->request('GET', '/login');
        $values = ['_username' => $email, '_password' => $password];
        if (null !== $csrfToken) {
            $values['_csrf_token'] = $csrfToken;
        }

        $client->submitForm('Log in', $values, 'POST', $server);
    }
}
