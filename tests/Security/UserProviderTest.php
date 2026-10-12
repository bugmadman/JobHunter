<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Domain\Shared\Email;
use App\Domain\User\UserServiceInterface;
use App\Entity\User;
use App\Security\UserProvider;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class UserProviderTest extends TestCase
{
    public function testPasswordUpgradeGoesThroughTheUserService(): void
    {
        $user = new User(Email::fromString('owner@example.com'), 'old-hash');

        $users = $this->createMock(UserServiceInterface::class);
        $users->expects(self::once())->method('upgradePasswordHash')->with($user, 'new-hash');

        $this->provider($users)->upgradePassword($user, 'new-hash');
    }

    public function testPasswordUpgradeOfAnotherUserClassIsRejected(): void
    {
        $users = $this->createMock(UserServiceInterface::class);
        $users->expects(self::never())->method('upgradePasswordHash');

        $this->expectException(UnsupportedUserException::class);

        $this->provider($users)->upgradePassword(self::createStub(PasswordAuthenticatedUserInterface::class), 'hash');
    }

    private function provider(UserServiceInterface $users): UserProvider
    {
        return new UserProvider(self::createStub(ManagerRegistry::class), $users);
    }
}
