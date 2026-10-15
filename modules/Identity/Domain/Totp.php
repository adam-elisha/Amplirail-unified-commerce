<?php

declare(strict_types=1);

namespace Modules\Identity\Domain;

/**
 * RFC 6238 time-based one-time passwords (authenticator apps) for the admin console. SHA-1, 30 s step, 6 digits,
 * +/- 1 step drift. The caller stores the last accepted step to stop a code being replayed.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(10, $bytes)));
    }

    /** @return int|null the matched time step, or null when the code is wrong */
    public static function verify(string $secret, string $code, int $now, ?int $lastUsedStep = null, int $window = 1): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $step = intdiv($now, 30);
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = $step + $i;
            if ($lastUsedStep !== null && $candidate <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::codeAt($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function codeAt(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
