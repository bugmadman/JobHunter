<?php

declare(strict_types=1);

namespace App\Tests\Domain\User;

use App\Domain\User\EmptyPassword;
use App\Domain\User\PlainPassword;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PlainPasswordTest extends TestCase
{
    public function testKeepsThePasswordAsGiven(): void
    {
        self::assertSame(' secret ', PlainPassword::fromString(' secret ')->value());
    }

    public function testJsonEncodingDoesNotExposeThePassword(): void
    {
        $json = json_encode(PlainPassword::fromString('secret'), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('secret', $json);
    }

    #[TestWith([''])]
    #[TestWith(["  \t\n"])]
    public function testRejectsAnEmptyPassword(string $password): void
    {
        $this->expectException(EmptyPassword::class);

        PlainPassword::fromString($password);
    }
}
