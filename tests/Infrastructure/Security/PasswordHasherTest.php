<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Domain\User\PlainPassword;
use App\Entity\User;
use App\Infrastructure\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;

final class PasswordHasherTest extends TestCase
{
    public function testVerifiesAgainstAHashOfTheUserHasher(): void
    {
        $hasher = $this->hasher(4);
        $hash = $hasher->hash(PlainPassword::fromString('secret'));

        self::assertTrue($hasher->verify($hash, 'secret'));
        self::assertFalse($hasher->verify($hash, 'wrong'));
    }

    public function testVerifiesAWhitespaceOnlyPasswordThatPlainPasswordRejects(): void
    {
        $hasher = $this->hasher(4);
        $hash = $hasher->hash(PlainPassword::fromString('secret'));

        self::assertFalse($hasher->verify($hash, '   '));
    }

    public function testAHashOfAnotherCostNeedsARehash(): void
    {
        $hash = $this->hasher(4)->hash(PlainPassword::fromString('secret'));

        self::assertFalse($this->hasher(4)->needsRehash($hash));
        self::assertTrue($this->hasher(5)->needsRehash($hash));
    }

    private function hasher(int $cost): PasswordHasher
    {
        return new PasswordHasher(new PasswordHasherFactory([
            User::class => ['algorithm' => 'bcrypt', 'cost' => $cost],
        ]));
    }
}
