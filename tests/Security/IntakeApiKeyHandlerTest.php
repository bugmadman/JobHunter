<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Domain\Shared\Email;
use App\Domain\User\IntakeApiKey;
use App\Entity\User;
use App\Repository\UserRepositoryInterface;
use App\Security\IntakeApiKeyHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

final class IntakeApiKeyHandlerTest extends TestCase
{
    public function testFindsTheUserByTheKey(): void
    {
        $apiKey = IntakeApiKey::generate();
        $user = new User(Email::fromString('owner@example.com'), 'hash');
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())
            ->method('findByApiKey')
            ->with(self::callback(static fn (IntakeApiKey $key): bool => $key->hash() === $apiKey->hash()))
            ->willReturn($user);

        $badge = new IntakeApiKeyHandler($users)->getUserBadgeFrom($apiKey->value());

        self::assertSame('owner@example.com', $badge->getUserIdentifier());
        self::assertSame($user, $badge->getUser());
    }

    public function testRejectsAnUnknownKey(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findByApiKey')->willReturn(null);

        $this->expectException(BadCredentialsException::class);

        new IntakeApiKeyHandler($users)->getUserBadgeFrom(IntakeApiKey::generate()->value());
    }

    public function testRejectsAMalformedKeyWithoutALookup(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('findByApiKey');

        $this->expectException(BadCredentialsException::class);

        new IntakeApiKeyHandler($users)->getUserBadgeFrom('not-a-key');
    }
}
