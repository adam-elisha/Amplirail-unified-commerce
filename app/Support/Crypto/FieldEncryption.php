<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use Connectors\Secrets\SecretsResolver;
use Illuminate\Encryption\Encrypter;

/**
 * Field-level encryption for PII (tax IDs, owners, contacts) and documents (SECURITY.md: Database).
 * The key is a dedicated secret (not APP_KEY) so it can be rotated and access-controlled separately.
 * Fingerprints (HMAC-SHA256 with a second key) allow exact matching, e.g. duplicate EIN detection, without decrypting.
 */
final class FieldEncryption
{
    private ?Encrypter $encrypter = null;

    public function __construct(
        private readonly SecretsResolver $secrets,
        private readonly string $keyRef,
        private readonly string $fingerprintRef,
    ) {}

    public function encrypt(string $plain): string
    {
        return $this->encrypter()->encryptString($plain);
    }

    public function decrypt(string $cipher): string
    {
        return $this->encrypter()->decryptString($cipher);
    }

    public function fingerprint(string $value): string
    {
        $normalized = strtolower((string) preg_replace('/[\s\-]/', '', $value));

        return hash_hmac('sha256', $normalized, $this->secrets->get($this->fingerprintRef));
    }

    private function encrypter(): Encrypter
    {
        if ($this->encrypter === null) {
            $raw = base64_decode($this->secrets->get($this->keyRef), true);
            if ($raw === false || strlen($raw) !== 32) {
                throw new \RuntimeException('Field encryption key must be 32 bytes, base64 encoded.');
            }
            $this->encrypter = new Encrypter($raw, 'aes-256-gcm');
        }

        return $this->encrypter;
    }
}
