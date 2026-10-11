<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Domain\User\PlainPassword;
use App\EventListener\UnknownUserLoginListener;
use App\Infrastructure\Security\PasswordHasherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class UnknownUserLoginListenerTest extends TestCase
{
    private const string DUMMY_HASH = '$argon2id$dummy';

    /**
     * @return iterable<string, array{AuthenticationException}>
     */
    public static function unknownUserFailures(): iterable
    {
        yield 'exposed' => [new UserNotFoundException()];
        yield 'hidden behind bad credentials' => [new BadCredentialsException('', 0, new UserNotFoundException())];
        yield 'deeper in the chain' => [
            new BadCredentialsException('', 0, new \RuntimeException('', 0, new UserNotFoundException())),
        ];
    }

    /**
     * @return iterable<string, array{AuthenticationException}>
     */
    public static function otherFailures(): iterable
    {
        yield 'wrong password' => [new BadCredentialsException('', 0, new \RuntimeException())];
        yield 'throttled' => [new TooManyLoginAttemptsAuthenticationException(15)];
    }

    #[DataProvider('unknownUserFailures')]
    public function testUnknownUserVerifiesTheSubmittedPasswordAgainstTheDummyHash(
        AuthenticationException $exception,
    ): void {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturn(self::DUMMY_HASH);
        $hasher->expects(self::once())->method('verify')->with(self::DUMMY_HASH, 'wrong');

        new UnknownUserLoginListener($hasher, new ArrayAdapter())($this->failure($exception, $this->passport('wrong')));
    }

    #[DataProvider('otherFailures')]
    public function testOtherFailuresAreLeftAlone(AuthenticationException $exception): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');
        $hasher->expects(self::never())->method('verify');

        new UnknownUserLoginListener($hasher, new ArrayAdapter())($this->failure($exception, $this->passport('wrong')));
    }

    public function testFailureBeforeThePassportIsLeftAlone(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');
        $hasher->expects(self::never())->method('verify');

        new UnknownUserLoginListener($hasher, new ArrayAdapter())($this->failure(new UserNotFoundException(), null));
    }

    public function testPassportWithoutPasswordIsLeftAlone(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');
        $hasher->expects(self::never())->method('verify');

        $passport = new SelfValidatingPassport(new UserBadge('nobody@example.com'));
        $listener = new UnknownUserLoginListener($hasher, new ArrayAdapter());

        $listener($this->failure(new UserNotFoundException(), $passport));
    }

    public function testTheDummyHashIsMadeOnceAndReused(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hash')
            ->with(self::isInstanceOf(PlainPassword::class))
            ->willReturn(self::DUMMY_HASH);
        $hasher->method('needsRehash')->willReturn(false);
        $hasher->expects(self::exactly(2))->method('verify')->with(self::DUMMY_HASH, 'wrong');
        $listener = new UnknownUserLoginListener($hasher, new ArrayAdapter());

        $listener($this->failure(new UserNotFoundException(), $this->passport('wrong')));
        $listener($this->failure(new UserNotFoundException(), $this->passport('wrong')));
    }

    public function testTheDummyHashIsRegeneratedWhenItNeedsARehash(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('login.dummy_password_hash')->set('$2y$04$outdated'));
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->method('needsRehash')->willReturnMap([['$2y$04$outdated', true], [self::DUMMY_HASH, false]]);
        $hasher->expects(self::once())->method('hash')->willReturn(self::DUMMY_HASH);
        $hasher->expects(self::exactly(2))->method('verify')->with(self::DUMMY_HASH, 'wrong');
        $listener = new UnknownUserLoginListener($hasher, $cache);

        $listener($this->failure(new UserNotFoundException(), $this->passport('wrong')));
        $listener($this->failure(new UserNotFoundException(), $this->passport('wrong')));

        self::assertSame(self::DUMMY_HASH, $cache->getItem('login.dummy_password_hash')->get());
    }

    public function testACorruptCachedDummyHashIsRegenerated(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('login.dummy_password_hash')->set(42));
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hash')->willReturn(self::DUMMY_HASH);
        $hasher->expects(self::once())->method('verify')->with(self::DUMMY_HASH, 'wrong');

        $listener = new UnknownUserLoginListener($hasher, $cache);

        $listener($this->failure(new UserNotFoundException(), $this->passport('wrong')));

        self::assertSame(self::DUMMY_HASH, $cache->getItem('login.dummy_password_hash')->get());
    }

    public function testAWhitespaceOnlyPasswordIsVerified(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturn(self::DUMMY_HASH);
        $hasher->expects(self::once())->method('verify')->with(self::DUMMY_HASH, '   ');

        $listener = new UnknownUserLoginListener($hasher, new ArrayAdapter());

        $listener($this->failure(new UserNotFoundException(), $this->passport('   ')));
    }

    public function testAnOverLongPasswordIsVerifiedNotHashed(): void
    {
        $password = str_repeat('a', 4097);
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('login.dummy_password_hash')->set(self::DUMMY_HASH));
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->method('needsRehash')->willReturn(false);
        $hasher->expects(self::never())->method('hash');
        $hasher->expects(self::once())->method('verify')->with(self::DUMMY_HASH, $password)->willReturn(false);
        $listener = new UnknownUserLoginListener($hasher, $cache);

        $listener($this->failure(new UserNotFoundException(), $this->passport($password)));
    }

    private function passport(string $password): Passport
    {
        return new Passport(new UserBadge('nobody@example.com'), new PasswordCredentials($password));
    }

    private function failure(AuthenticationException $exception, ?Passport $passport): LoginFailureEvent
    {
        return new LoginFailureEvent(
            $exception,
            self::createStub(AuthenticatorInterface::class),
            new Request(),
            null,
            'main',
            $passport,
        );
    }
}
