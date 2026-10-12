<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\EncryptedApiKey;
use App\Infrastructure\Security\ApiKeyDecryptionFailed;
use App\Infrastructure\Security\ApiKeyEncryptor;
use App\Infrastructure\Security\InvalidEncryptionKey;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ApiKeyEncryptorTest extends TestCase
{
    public function testDecryptsWhatItEncrypted(): void
    {
        $encryptor = new ApiKeyEncryptor(self::newKey());

        $encrypted = $encryptor->encrypt(ApiKey::fromString('AIza-secret'));

        self::assertStringNotContainsString('AIza-secret', $encrypted->value());
        self::assertSame('AIza-secret', $encryptor->decrypt($encrypted)->value());
    }

    public function testEncryptsTheSameKeyDifferentlyEachTime(): void
    {
        $encryptor = new ApiKeyEncryptor(self::newKey());
        $apiKey = ApiKey::fromString('AIza-secret');

        self::assertNotSame($encryptor->encrypt($apiKey)->value(), $encryptor->encrypt($apiKey)->value());
    }

    public function testRejectsATamperedCiphertext(): void
    {
        $encryptor = new ApiKeyEncryptor(self::newKey());
        $payload = base64_decode($encryptor->encrypt(ApiKey::fromString('AIza-secret'))->value(), true);
        self::assertIsString($payload);
        $payload[-1] = \chr(\ord($payload[-1]) ^ 1);

        $this->expectException(ApiKeyDecryptionFailed::class);

        $encryptor->decrypt(new EncryptedApiKey(base64_encode($payload)));
    }

    public function testRejectsACiphertextOfAnotherKey(): void
    {
        $encrypted = new ApiKeyEncryptor(self::newKey())->encrypt(ApiKey::fromString('AIza-secret'));

        try {
            new ApiKeyEncryptor(self::newKey())->decrypt($encrypted);
            self::fail('Decryption with another key must fail.');
        } catch (ApiKeyDecryptionFailed $exception) {
            self::assertStringNotContainsString('AIza-secret', $exception->getMessage());
        }
    }

    #[TestWith([''])]
    #[TestWith(['not base64!'])]
    #[TestWith(['c2hvcnQ='])]
    public function testRejectsAMalformedCiphertext(string $ciphertext): void
    {
        $this->expectException(ApiKeyDecryptionFailed::class);

        new ApiKeyEncryptor(self::newKey())->decrypt(new EncryptedApiKey($ciphertext));
    }

    public function testRejectsAMissingEncryptionKey(): void
    {
        $this->expectException(InvalidEncryptionKey::class);
        $this->expectExceptionMessage('is not set');

        new ApiKeyEncryptor('');
    }

    #[TestWith(['not base64!'])]
    #[TestWith(['c2hvcnQ='])]
    public function testRejectsAMalformedEncryptionKey(string $encryptionKey): void
    {
        $this->expectException(InvalidEncryptionKey::class);
        $this->expectExceptionMessage('must be the base64 of 32 random bytes');

        new ApiKeyEncryptor($encryptionKey);
    }

    private static function newKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }
}
