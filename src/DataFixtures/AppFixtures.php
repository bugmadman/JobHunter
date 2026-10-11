<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Shared\Email;
use App\Domain\User\PlainPassword;
use App\Domain\User\UserServiceInterface;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\When;

// Test only: in dev, doctrine:fixtures:load would purge the owner's real data and create a known login
#[When(env: 'test')]
final class AppFixtures extends Fixture
{
    public const string OWNER_EMAIL = 'owner@example.com';
    public const string OWNER_PASSWORD = 'owner';

    public function __construct(
        private readonly UserServiceInterface $users,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->users->create(Email::fromString(self::OWNER_EMAIL), PlainPassword::fromString(self::OWNER_PASSWORD));
    }
}
