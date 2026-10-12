<?php

declare(strict_types=1);

namespace App\Tests\Domain\User;

use App\Domain\User\IntakeApiKey;
use App\Domain\User\InvalidIntakeApiKey;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class IntakeApiKeyTest extends TestCase
{
    public function testGeneratesA256BitHexKey(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', IntakeApiKey::generate()->value());
    }

    public function testGeneratesADifferentKeyEachTime(): void
    {
        self::assertNotSame(IntakeApiKey::generate()->value(), IntakeApiKey::generate()->value());
    }

    public function testHashIsTheSha256OfTheKey(): void
    {
        $apiKey = IntakeApiKey::generate();

        self::assertSame(hash('sha256', $apiKey->value()), $apiKey->hash());
    }

    public function testParsesAWellFormedKey(): void
    {
        $value = IntakeApiKey::generate()->value();

        self::assertSame($value, IntakeApiKey::fromString($value)->value());
    }

    #[TestWith([''])]
    #[TestWith(['0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcde'])]
    #[TestWith(['0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0'])]
    #[TestWith(['0123456789ABCDEF0123456789abcdef0123456789abcdef0123456789abcdef'])]
    #[TestWith(['0123456789abcdeg0123456789abcdef0123456789abcdef0123456789abcdef'])]
    #[TestWith(["0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\n"])]
    #[TestWith([' 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'])]
    public function testRejectsAMalformedKey(string $value): void
    {
        $this->expectException(InvalidIntakeApiKey::class);

        IntakeApiKey::fromString($value);
    }
}
