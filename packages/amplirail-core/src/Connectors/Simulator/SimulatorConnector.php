<?php
declare(strict_types=1);
namespace AmpliRail\Connectors\Simulator;

use AmpliRail\Connectors\Contracts\{PaymentConnector, PaymentRequest, ConnectorResult};

/**
 * Deterministic test connector. Token tok_sim_approved approves; amount cents ending trigger outcomes:
 * .02 hard decline (05), .51 soft decline insufficient funds, .59 suspected fraud (hard), .65 needs 3DS, .99 provider timeout (retryable).
 */
final class SimulatorConnector implements PaymentConnector
{
    public function code(): string { return 'simulator'; }

    public function sale(PaymentRequest $r): ConnectorResult { return $this->decide($r); }
    public function authorize(PaymentRequest $r): ConnectorResult { return $this->decide($r); }
    public function capture(PaymentRequest $r): ConnectorResult { return $this->ok('captured'); }
    public function refund(PaymentRequest $r): ConnectorResult { return $this->ok('refunded'); }
    public function void(PaymentRequest $r): ConnectorResult { return $this->ok('voided'); }

    private function decide(PaymentRequest $r): ConnectorResult
    {
        $cents = abs($r->amount->minor) % 100;
        return match ($cents) {
            2  => new ConnectorResult(ConnectorResult::DECLINED, 'sim_'.substr(sha1($r->idempotencyKey),0,12), null, '05', 'Do not honor'),
            51 => new ConnectorResult(ConnectorResult::DECLINED, 'sim_'.substr(sha1($r->idempotencyKey),0,12), null, '51', 'Insufficient funds', softDecline: true),
            59 => new ConnectorResult(ConnectorResult::DECLINED, 'sim_'.substr(sha1($r->idempotencyKey),0,12), null, '59', 'Suspected fraud'),
            65 => new ConnectorResult(ConnectorResult::DECLINED, 'sim_'.substr(sha1($r->idempotencyKey),0,12), null, '1A', 'Authentication required', softDecline: true),
            99 => new ConnectorResult(ConnectorResult::ERROR, null, null, 'timeout', 'Provider timeout', retryableError: true),
            default => $r->paymentToken === 'tok_sim_approved'
                ? new ConnectorResult(ConnectorResult::APPROVED, 'sim_'.substr(sha1($r->idempotencyKey),0,12), 'SIM'.substr(sha1($r->orderReference),0,4), '00', 'Approved')
                : new ConnectorResult(ConnectorResult::DECLINED, null, null, '14', 'Invalid token'),
        };
    }

    private function ok(string $m): ConnectorResult
    {
        return new ConnectorResult(ConnectorResult::APPROVED, 'sim_'.bin2hex(random_bytes(6)), null, '00', ucfirst($m));
    }
}
