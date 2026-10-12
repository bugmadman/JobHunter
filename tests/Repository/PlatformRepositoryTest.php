<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Platform;
use App\Repository\PlatformRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlatformRepositoryTest extends KernelTestCase
{
    public function testListsThePlatformsByName(): void
    {
        $repository = self::getContainer()->get(PlatformRepository::class);
        self::assertInstanceOf(PlatformRepository::class, $repository);

        self::assertSame(
            ['Glassdoor', 'Indeed', 'LinkedIn', 'Upwork'],
            array_map(
                static fn (Platform $platform): string => $platform->getName(),
                $repository->findAllOrderedByName(),
            ),
        );
    }
}
