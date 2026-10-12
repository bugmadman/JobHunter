<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User\IntakeApiKey;
use App\Domain\User\InvalidIntakeApiKey;
use App\Repository\UserRepositoryInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * The user is looked up by the key's hash through the unique index: a lookup leaks no useful timing,
 * since the hash of a guess says nothing about how close the guess is to a 256-bit random key.
 */
final readonly class IntakeApiKeyHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $apiKey = IntakeApiKey::fromString($accessToken);
        } catch (InvalidIntakeApiKey) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        $user = $this->users->findByApiKey($apiKey);
        if (null === $user) {
            throw new BadCredentialsException('Invalid credentials.');
        }

        return new UserBadge($user->getUserIdentifier(), static fn () => $user);
    }
}
