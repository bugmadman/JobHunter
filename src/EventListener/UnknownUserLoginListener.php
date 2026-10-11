<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Domain\User\PlainPassword;
use App\Infrastructure\Security\PasswordHasherInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * A wrong password costs a hash verification, an unknown email would fail right after the DB lookup:
 * the difference in response time would confirm the owner's email.
 */
#[AsEventListener(dispatcher: 'security.event_dispatcher.main')]
final readonly class UnknownUserLoginListener
{
    private const string DUMMY_PASSWORD = 'dummy-password';
    private const string DUMMY_HASH_KEY = 'login.dummy_password_hash';

    /**
     * The dummy hash lives in a cache pool, not in a property: computing it costs a full hash, and a property
     * would pay it again in every PHP worker and after every worker restart, making those unknown-email
     * failures slower than a wrong password.
     */
    public function __construct(
        private PasswordHasherInterface $hasher,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        if (!self::isUnknownUser($event->getException())) {
            return;
        }

        $credentials = $event->getPassport()?->getBadge(PasswordCredentials::class);
        if (null === $credentials) {
            return;
        }

        // Verifying the submitted password, not hashing a constant, takes the hasher's shortcuts
        // (e.g. a password over 4096 bytes is rejected without hashing) just as a wrong password does
        $this->hasher->verify($this->dummyHash(), $credentials->getPassword());
    }

    private static function isUnknownUser(?\Throwable $exception): bool
    {
        for (; null !== $exception; $exception = $exception->getPrevious()) {
            if ($exception instanceof UserNotFoundException) {
                return true;
            }
        }

        return false;
    }

    private function dummyHash(): string
    {
        $item = $this->cache->getItem(self::DUMMY_HASH_KEY);
        $hash = $item->get();
        if (\is_string($hash) && !$this->hasher->needsRehash($hash)) {
            return $hash;
        }

        $hash = $this->hasher->hash(PlainPassword::fromString(self::DUMMY_PASSWORD));
        $this->cache->save($item->set($hash));

        return $hash;
    }
}
