<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\ValueObject\Email;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testIdentifierIsTheEmail(): void
    {
        $user = new User(Email::fromString('owner@example.com'), 'hash');

        self::assertSame('owner@example.com', $user->getUserIdentifier());
        self::assertTrue($user->getEmail()->equals(Email::fromString('owner@example.com')));
    }

    public function testEmptyPasswordHashIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(Email::fromString('owner@example.com'), '');
    }
}
