<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\DataFixtures\AppFixtures;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait LogsInAsOwner
{
    private function logInAsOwner(KernelBrowser $client): void
    {
        $users = $client->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $owner = $users->loadUserByIdentifier(AppFixtures::OWNER_EMAIL);
        self::assertInstanceOf(User::class, $owner);

        $client->loginUser($owner);
    }
}
