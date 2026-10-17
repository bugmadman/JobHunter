<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\ScanInstruction\ScanInstructionText;
use App\Entity\Platform;
use App\Entity\ScanInstruction;
use App\Repository\PlatformRepository;
use App\Repository\ScanInstructionRepository;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testListsNoInstructionsWhenThereAreNone(): void
    {
        $repository = self::getContainer()->get(ScanInstructionRepository::class);
        self::assertInstanceOf(ScanInstructionRepository::class, $repository);

        self::assertSame([], $repository->findAllOrderedByPlatformName());
    }

    public function testListsTheInstructionsByPlatformNameWithTheirPlatformsLoaded(): void
    {
        $repository = self::getContainer()->get(ScanInstructionRepository::class);
        self::assertInstanceOf(ScanInstructionRepository::class, $repository);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        foreach (['Upwork', 'Indeed', 'LinkedIn'] as $name) {
            $this->addInstruction($repository, $name);
        }
        $entityManager->clear();

        $instructions = $repository->findAllOrderedByPlatformName();

        foreach ($instructions as $instruction) {
            self::assertFalse($entityManager->getUnitOfWork()->isUninitializedObject($instruction->getPlatform()));
        }
        self::assertSame(
            ['Indeed', 'LinkedIn', 'Upwork'],
            array_map(
                static fn (ScanInstruction $instruction): string => $instruction->getPlatform()->getName(),
                $instructions,
            ),
        );
    }

    private function addInstruction(ScanInstructionRepository $repository, string $platformName): void
    {
        $platforms = self::getContainer()->get(PlatformRepository::class);
        self::assertInstanceOf(PlatformRepository::class, $platforms);
        $platform = $platforms->findByName($platformName);
        self::assertNotNull($platform);

        $repository->save(new ScanInstruction(
            $platform,
            ScanInstructionText::fromString('Search.'),
            new \DateTimeImmutable(),
        ));
    }
}
