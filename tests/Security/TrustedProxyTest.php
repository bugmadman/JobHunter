<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyTest extends WebTestCase
{
    private const string ENV = 'SYMFONY_TRUSTED_PROXIES';
    private const string PROXY = '172.18.0.1';
    private const string CLIENT = '203.0.113.7';
    private const string LAN_CLIENT = '192.168.1.50';

    private mixed $envValue;
    private mixed $serverValue;

    protected function setUp(): void
    {
        $this->envValue = $_ENV[self::ENV] ?? null;
        $this->serverValue = $_SERVER[self::ENV] ?? null;
        $_ENV[self::ENV] = '';
        $_SERVER[self::ENV] = '';
        Request::setTrustedProxies([], 0);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::restore($_ENV, $this->envValue);
        self::restore($_SERVER, $this->serverValue);
        // The kernel sets the trusted proxies statically and never resets them, so they would leak into later tests
        Request::setTrustedProxies([], 0);
    }

    public function testUsesTheClientIpAndSchemeForwardedByTheTrustedProxy(): void
    {
        $client = $this->createClientBehindTheProxy();

        $request = $this->requestHome($client, [
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => self::CLIENT,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame(self::CLIENT, $request->getClientIp());
        self::assertTrue($request->isSecure());
        self::assertResponseRedirects('https://localhost/login');
    }

    public function testTakesTheAddressTheProxySawNotTheOneTheClientClaimed(): void
    {
        $client = $this->createClientBehindTheProxy();

        $request = $this->requestHome($client, [
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.9, '.self::CLIENT,
        ]);

        self::assertSame(self::CLIENT, $request->getClientIp());
    }

    public function testIgnoresForwardedHeadersFromAClientThatReachesTheAppDirectly(): void
    {
        $client = $this->createClientBehindTheProxy();

        $request = $this->requestHome($client, [
            'REMOTE_ADDR' => self::LAN_CLIENT,
            'HTTP_X_FORWARDED_FOR' => self::CLIENT,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame(self::LAN_CLIENT, $request->getClientIp());
        self::assertFalse($request->isSecure());
        self::assertResponseRedirects('http://localhost/login');
    }

    public function testIgnoresTheForwardedHostPortAndPrefixEvenFromTheProxy(): void
    {
        $client = $this->createClientBehindTheProxy();

        $this->requestHome($client, [
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_X_FORWARDED_PREFIX' => '/prefix',
        ]);

        self::assertResponseRedirects('https://localhost/login');
    }

    public function testTrustsNoProxyUnlessOneIsConfigured(): void
    {
        $client = self::createClient();

        $request = $this->requestHome($client, [
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => self::CLIENT,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame(self::PROXY, $request->getClientIp());
        self::assertFalse($request->isSecure());
    }

    private function createClientBehindTheProxy(): KernelBrowser
    {
        $_ENV[self::ENV] = self::PROXY;
        $_SERVER[self::ENV] = self::PROXY;

        return self::createClient();
    }

    /**
     * @param array<mixed> $variables
     */
    private static function restore(array &$variables, mixed $value): void
    {
        if (null === $value) {
            unset($variables[self::ENV]);
        } else {
            $variables[self::ENV] = $value;
        }
    }

    /**
     * @param array<string, string> $server
     */
    private function requestHome(KernelBrowser $client, array $server): Request
    {
        $client->request('GET', '/', server: $server);
        $request = $client->getRequest();
        self::assertInstanceOf(Request::class, $request);

        return $request;
    }
}
