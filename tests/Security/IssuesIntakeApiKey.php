<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\DataFixtures\AppFixtures;
use App\Domain\User\IntakeApiKey;
use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait IssuesIntakeApiKey
{
    private function issueOwnerApiKey(KernelBrowser $client): IntakeApiKey
    {
        $container = $client->getContainer();
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $owner = $users->loadUserByIdentifier(AppFixtures::OWNER_EMAIL);
        self::assertInstanceOf(User::class, $owner);
        $service = $container->get(UserServiceInterface::class);
        self::assertInstanceOf(UserServiceInterface::class, $service);

        return $service->regenerateApiKey($owner);
    }
}
