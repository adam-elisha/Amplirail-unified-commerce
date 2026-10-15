<?php
declare(strict_types=1);
namespace AmpliRail\Links;

/**
 * Signed payment links and invoice links: pay.amplirail.com/l/<token>.
 * The amount, currency, merchant and expiry are signed, so nobody can change the price in the link.
 */
final class PaymentLinks
{
    public function __construct(private readonly string $signingKey) {}

    public function create(string $merchantId, int $amountMinor, string $currency, string $reference, int $expiresAt): string
    {
        $payload = self::b64(json_encode(['m' => $merchantId, 'a' => $amountMinor, 'c' => $currency, 'r' => $reference, 'e' => $expiresAt], JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $this->signingKey, true));
    }

    /** @return array{m:string,a:int,c:string,r:string,e:int}|null */
    public function verify(string $token, int $now): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        if (!hash_equals(self::b64(hash_hmac('sha256', $parts[0], $this->signingKey, true)), $parts[1])) return null;
        $d = json_decode(self::unb64($parts[0]), true);
        if (!is_array($d) || $d['e'] < $now) return null;
        return $d;
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function unb64(string $s): string { return base64_decode(strtr($s, '-_', '+/')); }
}
