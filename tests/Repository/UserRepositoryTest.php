<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\DataFixtures\AppFixtures;
use App\Domain\User\IntakeApiKey;
use App\Domain\User\UserServiceInterface;
use App\Entity\User;
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

    public function testFindsTheUserByTheApiKey(): void
    {
        $apiKey = $this->issueOwnerApiKey();

        self::assertSame(AppFixtures::OWNER_EMAIL, $this->repository()->findByApiKey($apiKey)?->getUserIdentifier());
    }

    public function testFindsNobodyByAnotherApiKey(): void
    {
        $this->issueOwnerApiKey();

        self::assertNull($this->repository()->findByApiKey(IntakeApiKey::generate()));
    }

    private function issueOwnerApiKey(): IntakeApiKey
    {
        $owner = $this->repository()->loadUserByIdentifier(AppFixtures::OWNER_EMAIL);
        self::assertInstanceOf(User::class, $owner);
        $users = self::getContainer()->get(UserServiceInterface::class);
        self::assertInstanceOf(UserServiceInterface::class, $users);

        return $users->regenerateApiKey($owner);
    }

    private function repository(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }
}
