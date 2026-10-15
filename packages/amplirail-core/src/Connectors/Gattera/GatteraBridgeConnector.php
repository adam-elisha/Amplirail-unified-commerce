<?php
declare(strict_types=1);
namespace AmpliRail\Connectors\Gattera;

use AmpliRail\Connectors\Contracts\{PaymentConnector, PaymentRequest, ConnectorResult};
use AmpliRail\Http\HttpClient;

/**
 * Hidden Gattera bridge (D29, D35) for EU acquiring banks.
 * BLOCKER before go-live: Gattera must provide hosted fields or tokenization. AmpliRail never sends raw card numbers.
 * Endpoint paths and field names are placeholders until Gattera's API documentation is in docs/providers/gattera/.
 */
final class GatteraBridgeConnector implements PaymentConnector
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly \Closure $apiKeyFor,       // fn(string $merchantAccountId): string
        private readonly \Closure $providerMidFor,  // fn(string $merchantAccountId): string
        private readonly array $paths = ['sale' => '/v1/sale', 'authorize' => '/v1/authorize', 'capture' => '/v1/capture', 'refund' => '/v1/refund', 'void' => '/v1/void'],
        private readonly int $timeoutMs = 8000,
    ) {}

    public function code(): string { return 'gattera_bridge'; }

    public function sale(PaymentRequest $r): ConnectorResult { return $this->send('sale', $r); }
    public function authorize(PaymentRequest $r): ConnectorResult { return $this->send('authorize', $r); }
    public function capture(PaymentRequest $r): ConnectorResult { return $this->send('capture', $r); }
    public function refund(PaymentRequest $r): ConnectorResult { return $this->send('refund', $r); }
    public function void(PaymentRequest $r): ConnectorResult { return $this->send('void', $r); }

    private function send(string $op, PaymentRequest $r): ConnectorResult
    {
        $mid = ($this->providerMidFor)($r->merchantAccountId);
        $body = ['merchant_id' => $mid, 'order_id' => $r->orderReference];
        if ($op !== 'void') { $body['amount'] = $r->amount->minor; $body['currency'] = $r->amount->currency; }
        if (in_array($op, ['sale', 'authorize'], true)) { $body['payment_token'] = $r->paymentToken; } // TODO confirm token field name with Gattera
        else { $body['transaction_id'] = $r->providerTransactionId; }

        try {
            $res = $this->http->post(rtrim($this->baseUrl, '/') . $this->paths[$op], [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . ($this->apiKeyFor)($r->merchantAccountId),
                'Idempotency-Key' => $r->idempotencyKey,
                'X-MID' => $mid,
            ], json_encode($body, JSON_THROW_ON_ERROR), $this->timeoutMs);
        } catch (\Throwable $e) {
            return new ConnectorResult(ConnectorResult::ERROR, null, null, 'network', 'Provider unreachable; outcome unknown, reconcile before retry');
        }
        $p = json_decode($res['body'], true) ?: [];
        $status = (string)($p['status'] ?? 'error');
        $approved = in_array($status, ['authorized', 'captured', 'approved', 'refunded', 'voided'], true);
        return new ConnectorResult(
            $approved ? ConnectorResult::APPROVED : ($status === 'declined' ? ConnectorResult::DECLINED : ConnectorResult::ERROR),
            $p['transaction_id'] ?? null, $p['auth_code'] ?? null, (string)($p['decline_code'] ?? ($approved ? '00' : '')),
            (string)($p['message'] ?? $status), raw: $p,
        );
    }
}
