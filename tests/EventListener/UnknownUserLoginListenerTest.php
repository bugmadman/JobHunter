<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Domain\User\PlainPassword;
use App\EventListener\UnknownUserLoginListener;
use App\Infrastructure\Security\PasswordHasherInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class UnknownUserLoginListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{AuthenticationException}>
     */
    public static function unknownUserFailures(): iterable
    {
        yield 'hidden behind bad credentials' => [new BadCredentialsException('', 0, new UserNotFoundException())];
        yield 'exposed' => [new UserNotFoundException()];
    }

    /**
     * @return iterable<string, array{AuthenticationException}>
     */
    public static function otherFailures(): iterable
    {
        yield 'wrong password' => [new BadCredentialsException()];
        yield 'throttled' => [new TooManyLoginAttemptsAuthenticationException(15)];
    }

    #[DataProvider('unknownUserFailures')]
    public function testUnknownUserCostsAPasswordHash(AuthenticationException $exception): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hash')->with(self::isInstanceOf(PlainPassword::class));

        new UnknownUserLoginListener($hasher)($this->failure($exception));
    }

    #[DataProvider('otherFailures')]
    public function testOtherFailuresAreLeftAlone(AuthenticationException $exception): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hash');

        new UnknownUserLoginListener($hasher)($this->failure($exception));
    }

    private function failure(AuthenticationException $exception): LoginFailureEvent
    {
        return new LoginFailureEvent(
            $exception,
            self::createStub(AuthenticatorInterface::class),
            new Request(),
            null,
            'main',
        );
    }
}
