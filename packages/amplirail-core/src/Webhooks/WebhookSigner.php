<?php
declare(strict_types=1);
namespace AmpliRail\Webhooks;

/**
 * Signs outgoing merchant webhooks. Header: AmpliRail-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "t.body">
 * Secrets use the arwhk_ prefix and live in the secrets manager. Receivers reject signatures older than 5 minutes.
 */
final class WebhookSigner
{
    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $secret, string $body, string $header, int $now, int $tolerance = 300): bool
    {
        if (!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $header, $m)) return false;
        if (abs($now - (int)$m[1]) > $tolerance) return false;
        return hash_equals(hash_hmac('sha256', $m[1] . '.' . $body, $secret), $m[2]);
    }
}
