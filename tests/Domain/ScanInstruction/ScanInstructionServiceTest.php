<?php

declare(strict_types=1);

namespace App\Tests\Domain\ScanInstruction;

use App\Domain\Platform\PlatformAccessType;
use App\Domain\ScanInstruction\ScanInstructionService;
use App\Domain\ScanInstruction\ScanInstructionText;
use App\Entity\Platform;
use App\Entity\ScanInstruction;
use App\Repository\ScanInstructionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ScanInstructionServiceTest extends TestCase
{
    public function testCreatesTheInstructionOfAPlatformWithoutOne(): void
    {
        $platform = new Platform('Upwork', PlatformAccessType::Browser);
        $clock = new MockClock('2026-10-17 12:00:00');
        $scanInstructions = $this->createMock(ScanInstructionRepositoryInterface::class);
        $scanInstructions->method('findByPlatform')->with($platform)->willReturn(null);
        $scanInstructions->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (ScanInstruction $saved): bool => $saved->getPlatform() === $platform
                && 'Search "Symfony".' === $saved->getText()
                && $saved->getUpdatedAt()->getTimestamp() === $clock->now()->getTimestamp()));

        new ScanInstructionService($scanInstructions, $clock)
            ->save($platform, ScanInstructionText::fromString('Search "Symfony".'));
    }

    public function testReplacesTheTextOfAnExistingInstruction(): void
    {
        $platform = new Platform('Upwork', PlatformAccessType::Browser);
        $clock = new MockClock('2026-10-17 12:00:00');
        $existing = new ScanInstruction(
            $platform,
            ScanInstructionText::fromString('Old.'),
            new \DateTimeImmutable('2026-10-01'),
        );
        $scanInstructions = $this->createMock(ScanInstructionRepositoryInterface::class);
        $scanInstructions->method('findByPlatform')->willReturn($existing);
        $scanInstructions->expects(self::once())->method('save')->with(self::identicalTo($existing));

        new ScanInstructionService($scanInstructions, $clock)
            ->save($platform, ScanInstructionText::fromString('New.'));

        self::assertSame('New.', $existing->getText());
        self::assertEquals($clock->now(), $existing->getUpdatedAt());
    }

    public function testDeletesTheInstruction(): void
    {
        $scanInstruction = new ScanInstruction(
            new Platform('Upwork', PlatformAccessType::Browser),
            ScanInstructionText::fromString('Search.'),
            new \DateTimeImmutable('2026-10-01'),
        );
        $scanInstructions = $this->createMock(ScanInstructionRepositoryInterface::class);
        $scanInstructions->expects(self::once())->method('remove')->with(self::identicalTo($scanInstruction));

        new ScanInstructionService($scanInstructions, new MockClock())->delete($scanInstruction);
    }
}
