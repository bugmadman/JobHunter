<?php

declare(strict_types=1);

namespace App\Tests\Domain\Shared;

use App\Domain\Shared\Email;
use App\Domain\Shared\InvalidEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testValueIsNormalized(): void
    {
        self::assertSame('owner@example.com', Email::fromString('  Owner@Example.COM ')->value);
    }

    public function testEqualEmailsAreEqual(): void
    {
        self::assertTrue(Email::fromString('owner@example.com')->equals(Email::fromString('OWNER@example.com')));
        self::assertFalse(Email::fromString('owner@example.com')->equals(Email::fromString('other@example.com')));
    }

    #[DataProvider('invalidEmails')]
    public function testInvalidEmailIsRejected(string $email): void
    {
        $this->expectException(InvalidEmail::class);

        Email::fromString($email);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEmails(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no at sign' => ['owner.example.com'];
        yield 'no domain' => ['owner@'];
    }
}
