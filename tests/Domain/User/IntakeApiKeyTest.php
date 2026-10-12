<?php

declare(strict_types=1);

namespace App\Tests\Domain\User;

use App\Domain\User\IntakeApiKey;
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
}
