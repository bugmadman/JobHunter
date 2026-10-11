<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\DataFixtures\AppFixtures;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRepositoryTest extends KernelTestCase
{
    #[TestWith([AppFixtures::OWNER_EMAIL])]
    #[TestWith(['Owner@Example.COM'])]
    #[TestWith(['  owner@example.com '])]
    public function testLoaderFindsTheUserByANormalizedEmail(string $identifier): void
    {
        $user = $this->repository()->loadUserByIdentifier($identifier);

        self::assertNotNull($user);
        self::assertSame(AppFixtures::OWNER_EMAIL, $user->getUserIdentifier());
    }

    public function testLoaderReturnsNullForAnUnknownEmail(): void
    {
        self::assertNull($this->repository()->loadUserByIdentifier('nobody@example.com'));
    }

    public function testLoaderReturnsNullForAnIdentifierThatIsNotAnEmail(): void
    {
        self::assertNull($this->repository()->loadUserByIdentifier('not-an-email'));
    }

    private function repository(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
