<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Llm\ApiKey;
use App\Domain\Llm\EncryptedApiKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The key is kept base64-encoded and decoded for each operation, so the binary copy can be wiped right after;
 * the env var itself stays in $_ENV and $_SERVER, which can't be wiped.
 */
final readonly class ApiKeyEncryptor implements ApiKeyEncryptorInterface
{
    public function __construct(
        #[Autowire(env: 'LLM_KEY_ENCRYPTION_KEY')]
        #[\SensitiveParameter]
        private string $encryptionKey,
    ) {
        $key = $this->key();
        sodium_memzero($key);
    }

    public function encrypt(ApiKey $apiKey): EncryptedApiKey
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->key();

        try {
            $ciphertext = sodium_crypto_secretbox($apiKey->value(), $nonce, $key);
        } finally {
            sodium_memzero($key);
        }

        return new EncryptedApiKey(sodium_bin2base64($nonce.$ciphertext, \SODIUM_BASE64_VARIANT_ORIGINAL));
    }

    public function decrypt(EncryptedApiKey $encryptedApiKey): ApiKey
    {
        $payload = $this->decodePayload($encryptedApiKey);
        $key = $this->key();

        try {
            $plaintext = sodium_crypto_secretbox_open(
                substr($payload, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($payload, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $key,
            );
        } finally {
            sodium_memzero($key);
        }

        if (false === $plaintext) {
            throw ApiKeyDecryptionFailed::create();
        }

        return ApiKey::fromString($plaintext);
    }

    private function key(): string
    {
        if ('' === $this->encryptionKey) {
            throw InvalidEncryptionKey::notSet();
        }

        try {
            $key = sodium_base642bin($this->encryptionKey, \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            throw InvalidEncryptionKey::malformed();
        }

        if (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            sodium_memzero($key);

            throw InvalidEncryptionKey::malformed();
        }

        return $key;
    }

    private function decodePayload(EncryptedApiKey $encryptedApiKey): string
    {
        try {
            $payload = sodium_base642bin($encryptedApiKey->value(), \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            throw ApiKeyDecryptionFailed::create();
        }

        if (\strlen($payload) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw ApiKeyDecryptionFailed::create();
        }

        return $payload;
    }
}
