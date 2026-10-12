<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Shared\Email;
use App\Domain\User\IntakeApiKey;
use App\Entity\User;
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

    public function testPasswordHashIsChanged(): void
    {
        $user = new User(Email::fromString('owner@example.com'), 'old-hash');

        $user->changePasswordHash('new-hash');

        self::assertSame('new-hash', $user->getPassword());
    }

    public function testChangeToAnEmptyPasswordHashIsRejected(): void
    {
        $user = new User(Email::fromString('owner@example.com'), 'old-hash');

        try {
            $user->changePasswordHash('');
            self::fail('An empty password hash was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame('old-hash', $user->getPassword());
        }
    }

    public function testSessionKeepsTheCrc32cOfThePasswordHashInsteadOfTheHash(): void
    {
        $hash = '$2y$13$abcdefghijklmnopqrstuuJ5hZ0bS9Qm7m3p3Yc8eGqkqJt9w1x2y';
        $user = new User(Email::fromString('owner@example.com'), $hash);
        new \ReflectionProperty(User::class, 'id')->setValue($user, 42);

        $serialized = serialize($user);
        $restored = unserialize($serialized);

        self::assertStringNotContainsString($hash, $serialized);
        self::assertInstanceOf(User::class, $restored);
        self::assertSame(hash('crc32c', $hash), $restored->getPassword());
        self::assertSame(42, $restored->getId());
        self::assertSame('owner@example.com', $restored->getUserIdentifier());
        self::assertSame($hash, $user->getPassword());
    }

    public function testApiKeyIsStoredAsItsHash(): void
    {
        $user = new User(Email::fromString('owner@example.com'), 'hash');
        $apiKey = IntakeApiKey::generate();

        self::assertFalse($user->hasApiKey());

        $user->replaceApiKey($apiKey);

        self::assertTrue($user->hasApiKey());
        self::assertSame($apiKey->hash(), new \ReflectionProperty(User::class, 'apiKeyHash')->getValue($user));
    }
}
