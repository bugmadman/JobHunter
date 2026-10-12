<?php

declare(strict_types=1);

namespace App\Tests\Domain\User;

use App\Domain\Shared\Email;
use App\Domain\Shared\Locale;
use App\Domain\User\PlainPassword;
use App\Domain\User\UserAlreadyExists;
use App\Domain\User\UserService;
use App\Entity\User;
use App\Infrastructure\Security\PasswordHasherInterface;
use App\Repository\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function testCreatesAndSavesTheUser(): void
    {
        $password = PlainPassword::fromString('secret');

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('exists')->willReturn(false);
        $users->expects(self::once())
            ->method('save')
            ->with(self::callback(
                static fn (User $user): bool => 'owner@example.com' === $user->getUserIdentifier()
                    && 'hashed-secret' === $user->getPassword(),
            ));

        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hash')->with($password)->willReturn('hashed-secret');

        $user = new UserService($users, $hasher)->create(Email::fromString('owner@example.com'), $password);

        self::assertSame('owner@example.com', $user->getUserIdentifier());
        self::assertSame('hashed-secret', $user->getPassword());
    }

    public function testRejectsASecondUser(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('exists')->willReturn(true);
        $users->expects(self::never())->method('save');

        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');

        $this->expectException(UserAlreadyExists::class);

        new UserService($users, $hasher)->create(
            Email::fromString('owner@example.com'),
            PlainPassword::fromString('secret'),
        );
    }

    public function testUpgradedPasswordHashIsSaved(): void
    {
        $user = new User(Email::fromString('owner@example.com'), 'old-hash');

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (User $saved): bool => $saved === $user
                && 'new-hash' === $saved->getPassword()));

        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');

        new UserService($users, $hasher)->upgradePasswordHash($user, 'new-hash');
    }

    public function testChangedLocaleIsSaved(): void
    {
        $user = new User(Email::fromString('owner@example.com'), 'hash');

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (User $saved): bool => $saved === $user
                && Locale::Ru === $saved->getLocale()));

        new UserService($users, self::createStub(PasswordHasherInterface::class))->changeLocale($user, Locale::Ru);
    }
}
