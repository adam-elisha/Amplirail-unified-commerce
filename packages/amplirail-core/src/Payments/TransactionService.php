<?php
declare(strict_types=1);
namespace AmpliRail\Payments;

use AmpliRail\Money\Money;
use AmpliRail\Connectors\Contracts\{PaymentConnector, PaymentRequest, ConnectorResult};
use AmpliRail\Routing\{Router, MerchantAccount};
use AmpliRail\Screening\Screening;
use AmpliRail\Ledger\Ledger;
use AmpliRail\Events\Outbox;

/**
 * Launch-slice payment orchestration (hot path, Section 4.5 rule 2):
 * idempotency -> screening -> routing -> connector -> transaction record + ledger + outbox (one DB transaction in production).
 * Failover only on retryable provider errors; declines are never rerouted (rule 10); unknown outcomes are reconciled, never blindly retried.
 */
final class TransactionService
{
    /** @var array<string,array> */
    public array $transactions = [];

    /** @param array<string,PaymentConnector> $connectors keyed by code @param MerchantAccount[] $accounts */
    public function __construct(
        private readonly array $connectors,
        private readonly array $accounts,
        private readonly Router $router,
        private readonly Screening $screening,
        private readonly IdempotencyStore $idem,
        private readonly Ledger $ledger,
        private readonly Outbox $outbox,
    ) {}

    public function sale(string $tenant, string $merchantId, string $idempotencyKey, Money $amount, string $token, string $orderRef, array $rules): array
    {
        $hash = hash('sha256', implode('|', [$merchantId, $amount->minor, $amount->currency, $token, $orderRef]));
        $b = $this->idem->begin($tenant, $idempotencyKey, $hash);
        if ($b['status'] === 'replay') return $b['response'];
        if ($b['status'] === 'conflict') return ['status' => 'rejected', 'reason' => 'idempotency_key_reused_with_different_request'];
        if ($b['status'] === 'in_flight') return ['status' => 'rejected', 'reason' => 'request_in_progress'];

        try {
            $scr = $this->screening->check($token, $amount->minor, $rules);
            if (!$scr['allow']) return $this->finish($tenant, $idempotencyKey, $hash, $this->record($merchantId, null, $amount, 'blocked', null, $scr['reason'], 'sale'));

            $cands = $this->router->candidates($this->accounts, $merchantId, $amount->currency, $amount->minor);
            if (!$cands) return $this->finish($tenant, $idempotencyKey, $hash, $this->record($merchantId, null, $amount, 'failed', null, 'no_eligible_mid', 'sale'));

            $attempts = [];
            foreach ($cands as $mid) {
                $req = new PaymentRequest($idempotencyKey . ':' . $mid->id, $mid->id, $amount, $token, $orderRef);
                $res = $this->connectors[$mid->connector]->sale($req);
                $attempts[] = ['mid' => $mid->id, 'connector' => $mid->connector, 'outcome' => $res->outcome, 'code' => $res->responseCode];
                if ($res->outcome === ConnectorResult::ERROR && $res->retryableError) continue;   // safe failover
                return $this->finish($tenant, $idempotencyKey, $hash, $this->fromResult($merchantId, $mid, $amount, $res, $attempts));
            }
            return $this->finish($tenant, $idempotencyKey, $hash, $this->record($merchantId, null, $amount, 'failed', null, 'all_routes_unavailable', 'sale', $attempts));
        } catch (\Throwable $e) {
            $this->idem->release($tenant, $idempotencyKey);
            throw $e;
        }
    }

    public function refund(string $tenant, string $transactionId, Money $amount, string $idempotencyKey): array
    {
        $t = $this->transactions[$transactionId] ?? null;
        if (!$t || $t['status'] !== 'approved') return ['status' => 'rejected', 'reason' => 'not_refundable'];
        if ($amount->currency !== $t['currency']) return ['status' => 'rejected', 'reason' => 'currency_mismatch'];
        if ($amount->minor > $t['amount_minor'] - $t['refunded_minor']) return ['status' => 'rejected', 'reason' => 'over_refundable_amount'];
        $hash = hash('sha256', $transactionId . '|' . $amount->minor);
        $b = $this->idem->begin($tenant, $idempotencyKey, $hash);
        if ($b['status'] === 'replay') return $b['response'];
        if ($b['status'] !== 'new') return ['status' => 'rejected', 'reason' => $b['status']];

        $mid = $this->findAccount($t['mid']);
        $res = $this->connectors[$mid->connector]->refund(new PaymentRequest($idempotencyKey, $mid->id, $amount, 'tok_refund', $t['id'], $t['provider_txn_id']));
        if (!$res->approved()) { $out = ['status' => 'failed', 'reason' => $res->message]; $this->idem->complete($tenant, $idempotencyKey, $hash, $out); return $out; }
        $this->transactions[$transactionId]['refunded_minor'] += $amount->minor;
        $this->ledger->post('jr_' . bin2hex(random_bytes(6)), 'refund:' . $transactionId, [
            ['merchant_payable:' . $t['merchant'], $amount], ['processor_receivable:' . $mid->id, $amount->negate()]]);
        $this->outbox->add('refund.succeeded', ['transaction_id' => $transactionId, 'amount_minor' => $amount->minor, 'currency' => $amount->currency]);
        $out = ['status' => 'refunded', 'transaction_id' => $transactionId, 'refunded_minor' => $this->transactions[$transactionId]['refunded_minor']];
        $this->idem->complete($tenant, $idempotencyKey, $hash, $out);
        return $out;
    }

    private function fromResult(string $merchantId, MerchantAccount $mid, Money $amount, ConnectorResult $r, array $attempts): array
    {
        $status = match ($r->outcome) { ConnectorResult::APPROVED => 'approved', ConnectorResult::DECLINED => 'declined', default => 'unknown' };
        $rec = $this->record($merchantId, $mid, $amount, $status, $r->providerTransactionId, $r->message, 'sale', $attempts, $r->responseCode, $r->softDecline);
        if ($status === 'approved') {
            $this->ledger->post('jr_' . bin2hex(random_bytes(6)), 'sale:' . $rec['id'], [
                ['processor_receivable:' . $mid->id, $amount], ['merchant_payable:' . $merchantId, $amount->negate()]]);
        }
        return $rec;
    }

    private function record(string $merchantId, ?MerchantAccount $mid, Money $amount, string $status, ?string $ptid, ?string $reason, string $kind, array $attempts = [], ?string $code = null, bool $soft = false): array
    {
        $id = 'txn_' . bin2hex(random_bytes(8));
        $rec = ['id' => $id, 'kind' => $kind, 'merchant' => $merchantId, 'mid' => $mid?->id, 'connector' => $mid?->connector, 'status' => $status,
            'amount_minor' => $amount->minor, 'currency' => $amount->currency, 'refunded_minor' => 0, 'provider_txn_id' => $ptid,
            'reason' => $reason, 'response_code' => $code, 'soft_decline' => $soft, 'attempts' => $attempts];
        $this->transactions[$id] = $rec;
        $this->outbox->add('transaction.' . $status, ['transaction_id' => $id, 'merchant_id' => $merchantId, 'amount_minor' => $amount->minor, 'currency' => $amount->currency]);
        return $rec;
    }

    private function finish(string $tenant, string $key, string $hash, array $rec): array
    {
        $out = ['status' => $rec['status'], 'transaction_id' => $rec['id'], 'reason' => $rec['reason'], 'response_code' => $rec['response_code'], 'attempts' => count($rec['attempts'])];
        $this->idem->complete($tenant, $key, $hash, $out);
        return $out;
    }

    private function findAccount(string $id): MerchantAccount
    {
        foreach ($this->accounts as $a) if ($a->id === $id) return $a;
        throw new \RuntimeException('MID not found');
    }
}
