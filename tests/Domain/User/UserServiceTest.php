<?php

declare(strict_types=1);

namespace App\Tests\Domain\User;

use App\Domain\Shared\InvalidEmail;
use App\Domain\User\EmptyPassword;
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
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('exists')->willReturn(false);
        $users->expects(self::once())
            ->method('save')
            ->with(self::callback(
                static fn (User $user): bool => 'owner@example.com' === $user->getUserIdentifier()
                    && 'hashed-secret' === $user->getPassword(),
            ));

        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hash')
            ->with(self::callback(static fn (PlainPassword $password): bool => ' secret ' === $password->value))
            ->willReturn('hashed-secret');

        $user = new UserService($users, $hasher)->create('  Owner@Example.COM ', ' secret ');

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

        new UserService($users, $hasher)->create('owner@example.com', 'secret');
    }

    public function testRejectsAnInvalidEmail(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('exists')->willReturn(false);
        $users->expects(self::never())->method('save');

        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');

        $this->expectException(InvalidEmail::class);

        new UserService($users, $hasher)->create('not-an-email', 'secret');
    }

    public function testRejectsAnEmptyPassword(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('exists')->willReturn(false);
        $users->expects(self::never())->method('save');

        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');

        $this->expectException(EmptyPassword::class);

        new UserService($users, $hasher)->create('owner@example.com', '');
    }
}
