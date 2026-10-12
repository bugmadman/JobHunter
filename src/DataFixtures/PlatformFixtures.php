<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Platform\PlatformAccessType;
use App\Entity\Platform;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When(env: 'test')]
final class PlatformFixtures extends Fixture
{
    public const array NAMES = ['Upwork', 'LinkedIn', 'Glassdoor', 'Indeed'];

    public function load(ObjectManager $manager): void
    {
        foreach (self::NAMES as $name) {
            $manager->persist(new Platform($name, PlatformAccessType::Browser));
        }

        $manager->flush();
    }
}
