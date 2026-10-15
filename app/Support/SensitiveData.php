<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detection and masking of card numbers, secrets and PII (AGENTS.md rules 1-3, SECURITY.md logging rules).
 */
final class SensitiveData
{
    /** True when the string contains a 13-19 digit run (spaces/dashes allowed) that passes the Luhn check. */
    public static function containsPan(string $value): bool
    {
        if (! preg_match_all('/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/', $value, $m)) {
            return false;
        }
        foreach ($m[0] as $candidate) {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';
            if (strlen($digits) >= 13 && strlen($digits) <= 19 && self::luhn($digits)) {
                return true;
            }
        }

        return false;
    }

    /** Recursively scans any payload for PAN-like values. Returns the offending key path or null. */
    public static function findPan(mixed $payload, string $path = ''): ?string
    {
        if (is_string($payload)) {
            return self::containsPan($payload) ? ($path === '' ? '(root)' : $path) : null;
        }
        if (is_int($payload)) {
            return self::findPan((string) $payload, $path);
        }
        if (is_array($payload)) {
            foreach ($payload as $k => $v) {
                $found = self::findPan($v, $path === '' ? (string) $k : $path.'.'.$k);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }

    /** Masks PANs, AmpliRail keys, bearer tokens, emails and long digit runs in free text (logs). */
    public static function mask(string $text): string
    {
        $text = (string) preg_replace_callback('/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/', function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]) ?? '';

            return self::luhn($digits) ? '[PAN '.substr($digits, -4).']' : '[DIGITS]';
        }, $text);
        $text = (string) preg_replace('/\b(ar(?:sec|pub|rst|whk)_(?:sbx|live)_)[A-Za-z0-9]+/', '$1****', $text);
        $text = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1****', $text);
        $text = (string) preg_replace('/(security_key|password|secret|code)(["\']?\s*[:=]\s*["\']?)[^"\'&\s,}]+/i', '$1$2****', $text);

        return (string) preg_replace_callback('/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', fn (array $m) => $m[1].'***@'.$m[2], $text);
    }

    /** Keys that must never appear in an inbound payload (raw bank data). */
    public const FORBIDDEN_KEYS = ['account_number', 'routing_number', 'iban', 'card_number', 'pan', 'cvv', 'cvc', 'ccnumber', 'ssn'];

    /** @param array<mixed> $payload */
    public static function findForbiddenKey(array $payload, string $path = ''): ?string
    {
        foreach ($payload as $k => $v) {
            $p = $path === '' ? (string) $k : $path.'.'.$k;
            if (is_string($k) && in_array(strtolower($k), self::FORBIDDEN_KEYS, true)) {
                return $p;
            }
            if (is_array($v) && ($found = self::findForbiddenKey($v, $p)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
