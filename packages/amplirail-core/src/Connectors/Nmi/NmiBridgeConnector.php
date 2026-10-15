<?php
declare(strict_types=1);
namespace AmpliRail\Connectors\Nmi;

use AmpliRail\Connectors\Contracts\{PaymentConnector, PaymentRequest, ConnectorResult};
use AmpliRail\Http\HttpClient;

/**
 * Hidden NMI bridge (D29). Merchants never see NMI.
 * Field names follow the provider's server-to-server API documentation kept ONLY in docs/providers/nmi/ (rule 13).
 * Verify every field against those docs before go-live. Card data never enters AmpliRail: only payment_token from hosted fields.
 */
final class NmiBridgeConnector implements PaymentConnector
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $endpoint,          // from config, e.g. provider transact URL
        private readonly \Closure $securityKeyFor,  // fn(string $merchantAccountId): string  (from secrets manager, never logged)
        private readonly int $timeoutMs = 8000,
    ) {}

    public function code(): string { return 'nmi_bridge'; }

    public function sale(PaymentRequest $r): ConnectorResult { return $this->send('sale', $r, true); }
    public function authorize(PaymentRequest $r): ConnectorResult { return $this->send('auth', $r, true); }
    public function capture(PaymentRequest $r): ConnectorResult { return $this->send('capture', $r, false); }
    public function refund(PaymentRequest $r): ConnectorResult { return $this->send('refund', $r, false); }
    public function void(PaymentRequest $r): ConnectorResult { return $this->send('void', $r, false); }

    private function send(string $type, PaymentRequest $r, bool $needsToken): ConnectorResult
    {
        $f = [
            'security_key' => ($this->securityKeyFor)($r->merchantAccountId),
            'type' => $type,
            'orderid' => $r->orderReference,
        ];
        if ($type !== 'void') {
            $f['amount'] = self::decimal($r->amount->minor);
            $f['currency'] = $r->amount->currency;
        }
        if ($needsToken) { $f['payment_token'] = $r->paymentToken; }
        else { $f['transactionid'] = (string)$r->providerTransactionId; }
        if ($r->initiator === 'merchant') { $f['initiated_by'] = 'merchant'; }

        try {
            $res = $this->http->post($this->endpoint, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($f), $this->timeoutMs);
        } catch (\Throwable $e) {
            // Unknown outcome: do not retry blindly; reconcile by orderid query (idempotency rule).
            return new ConnectorResult(ConnectorResult::ERROR, null, null, 'network', 'Provider unreachable; outcome unknown, reconcile before retry');
        }
        parse_str($res['body'], $p);
        $redacted = array_diff_key($p, array_flip(['security_key']));
        $resp = (string)($p['response'] ?? '3');
        return match ($resp) {
            '1' => new ConnectorResult(ConnectorResult::APPROVED, $p['transactionid'] ?? null, $p['authcode'] ?? null, (string)($p['response_code'] ?? '100'), (string)($p['responsetext'] ?? 'Approved'), raw: $redacted),
            '2' => new ConnectorResult(ConnectorResult::DECLINED, $p['transactionid'] ?? null, null, (string)($p['response_code'] ?? ''), (string)($p['responsetext'] ?? 'Declined'), softDecline: self::isSoft((string)($p['response_code'] ?? '')), raw: $redacted),
            default => new ConnectorResult(ConnectorResult::ERROR, $p['transactionid'] ?? null, null, (string)($p['response_code'] ?? ''), (string)($p['responsetext'] ?? 'Error'), raw: $redacted),
        };
    }

    /** Provider decline codes treated as soft (retry may succeed). Confirm list against provider docs. */
    private static function isSoft(string $code): bool { return in_array($code, ['202', '203', '221', '223', '240', '460'], true); }

    private static function decimal(int $minor): string { return intdiv($minor, 100) . '.' . str_pad((string)(abs($minor) % 100), 2, '0', STR_PAD_LEFT); }
}
