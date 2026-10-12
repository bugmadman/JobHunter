<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Shared\Locale;
use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocaleControllerTest extends WebTestCase
{
    use LogsInAsOwner;

    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const array CROSS_SITE = ['HTTP_SEC_FETCH_SITE' => 'cross-site'];
    private const string WELL_FORMED_TOKEN = 'b8L2Vq9xR4mT7nK1wZ3cY6pA';

    public function testTheSwitcherIsShownWithTheCurrentLocaleActive(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $client->request('GET', '/');

        self::assertSelectorExists('form[action="/locale"] button[name="locale"][value="ru"]');
        self::assertSelectorExists('form[action="/locale"] button.active[value="en"]');
    }

    public function testChangesTheLocaleAndRedirectsBack(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $users = $this->mockUserService($client);
        $users->expects(self::once())
            ->method('changeLocale')
            ->with(self::isInstanceOf(User::class), Locale::Ru);

        $this->post($client, ['locale' => 'ru', '_target' => '/admin']);

        self::assertResponseRedirects('/admin');
    }

    #[TestWith(['https://evil.example/'])]
    #[TestWith(['//evil.example/'])]
    #[TestWith(['/\\evil.example/'])]
    #[TestWith([''])]
    public function testRedirectsHomeForATargetOffTheSite(string $target): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $this->mockUserService($client)->expects(self::once())->method('changeLocale');

        $this->post($client, ['locale' => 'ru', '_target' => $target]);

        self::assertResponseRedirects('/');
    }

    public function testRejectsAnUnknownLocale(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $this->mockUserService($client)->expects(self::never())->method('changeLocale');

        $this->post($client, ['locale' => 'de', '_target' => '/']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRejectsACrossSiteRequest(): void
    {
        $client = self::createClient();
        $this->logInAsOwner($client);
        $this->mockUserService($client)->expects(self::never())->method('changeLocale');

        $client->request(
            'POST',
            '/locale',
            ['locale' => 'ru', '_target' => '/', '_csrf_token' => self::WELL_FORMED_TOKEN],
            server: self::CROSS_SITE,
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/locale', ['locale' => 'ru'], server: self::SAME_ORIGIN);

        self::assertResponseRedirects('/login');
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(KernelBrowser $client, array $fields): void
    {
        $client->request(
            'POST',
            '/locale',
            $fields + ['_csrf_token' => self::WELL_FORMED_TOKEN],
            server: self::SAME_ORIGIN,
        );
    }

    private function mockUserService(KernelBrowser $client): UserServiceInterface&MockObject
    {
        $users = $this->createMock(UserServiceInterface::class);
        $client->getContainer()->set(UserServiceInterface::class, $users);

        return $users;
    }
}
