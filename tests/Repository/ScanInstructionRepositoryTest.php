<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\ScanInstruction\ScanInstructionText;
use App\Entity\Platform;
use App\Entity\ScanInstruction;
use App\Repository\PlatformRepository;
use App\Repository\ScanInstructionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScanInstructionRepositoryTest extends KernelTestCase
{
    public function testListsThePlatformsWithoutAnInstructionByName(): void
    {
        $platforms = self::getContainer()->get(PlatformRepository::class);
        self::assertInstanceOf(PlatformRepository::class, $platforms);
        $repository = self::getContainer()->get(ScanInstructionRepository::class);
        self::assertInstanceOf(ScanInstructionRepository::class, $repository);
        $upwork = $platforms->findByName('Upwork');
        self::assertNotNull($upwork);

        $repository->save(new ScanInstruction(
            $upwork,
            ScanInstructionText::fromString('Search.'),
            new \DateTimeImmutable(),
        ));

        self::assertSame(
            ['Glassdoor', 'Indeed', 'LinkedIn'],
            array_map(
                static fn (Platform $platform): string => $platform->getName(),
                $repository->findPlatformsWithoutInstruction(),
            ),
        );
        self::assertSame($upwork, $repository->findByPlatform($upwork)?->getPlatform());
    }
}
