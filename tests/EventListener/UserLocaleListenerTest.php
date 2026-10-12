<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\DataFixtures\AppFixtures;
use App\Domain\Shared\Locale;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserLocaleListenerTest extends WebTestCase
{
    public function testPagesUseTheUserLocale(): void
    {
        $client = self::createClient();
        $this->logInWithLocale($client, Locale::Ru);

        $client->request('GET', '/');
        self::assertSelectorExists('html[lang="ru"]');
        self::assertSelectorTextContains('h1', 'Вакансии');

        $client->request('GET', '/admin');
        self::assertSelectorTextContains('h1', 'Настройки');
    }

    public function testPagesUseTheDefaultLocaleForTheDefaultUser(): void
    {
        $client = self::createClient();
        $this->logInWithLocale($client, Locale::En);

        $client->request('GET', '/');
        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('h1', 'Job postings');
    }

    private function logInWithLocale(KernelBrowser $client, Locale $locale): void
    {
        $users = $client->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $owner = $users->loadUserByIdentifier(AppFixtures::OWNER_EMAIL);
        self::assertInstanceOf(User::class, $owner);
        $owner->changeLocale($locale);
        $users->save($owner);

        $client->loginUser($owner);
    }
}
