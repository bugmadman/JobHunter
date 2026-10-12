<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\Skill\SkillName;
use App\Entity\Skill;
use App\Repository\SkillRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SkillRepositoryTest extends KernelTestCase
{
    public function testFindsOnlyTheSkillsWithTheGivenNames(): void
    {
        $php = new Skill(SkillName::fromString('php'));
        $symfony = new Skill(SkillName::fromString('symfony'));
        $this->repository()->save($php);
        $this->repository()->save($symfony);
        $this->repository()->save(new Skill(SkillName::fromString('go')));

        $found = $this->repository()->findByNames([
            SkillName::fromString('symfony'),
            SkillName::fromString('php'),
            SkillName::fromString('rust'),
        ]);

        self::assertEqualsCanonicalizing(
            ['php', 'symfony'],
            array_map(static fn (Skill $skill): string => $skill->getName(), $found),
        );
    }

    public function testFindsNothingForNoNames(): void
    {
        $this->repository()->save(new Skill(SkillName::fromString('php')));

        self::assertSame([], $this->repository()->findByNames([]));
    }

    private function repository(): SkillRepository
    {
        $repository = self::getContainer()->get(SkillRepository::class);
        self::assertInstanceOf(SkillRepository::class, $repository);

        return $repository;
    }
}
