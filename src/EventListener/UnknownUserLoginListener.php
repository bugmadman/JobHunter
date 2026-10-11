<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\User\PlainPassword;
use App\Infrastructure\Security\PasswordHasherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * A wrong password costs a hash verification, an unknown email would fail right after the DB lookup:
 * the difference in response time would confirm the owner's email.
 */
#[AsEventListener]
final readonly class UnknownUserLoginListener
{
    private const string DUMMY_PASSWORD = 'dummy-password';

    public function __construct(
        private PasswordHasherInterface $hasher,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        if (!self::isUnknownUser($event->getException())) {
            return;
        }

        // Hashing costs as much as verifying and follows the configured algorithm and cost, unlike a stored dummy hash
        $this->hasher->hash(PlainPassword::fromString(self::DUMMY_PASSWORD));
    }

    private static function isUnknownUser(AuthenticationException $exception): bool
    {
        return $exception instanceof UserNotFoundException
            || $exception->getPrevious() instanceof UserNotFoundException;
    }
}
