<?php

declare(strict_types=1);

namespace App\Services;

use AmpliRail\Money\Money;
use App\Support\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Disputes\Infrastructure\AlertSetting;
use Modules\Disputes\Infrastructure\Dispute;
use Modules\Disputes\Infrastructure\DisputeAlert;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Infrastructure\Transaction;

/**
 * Handles a pre-dispute alert from Ethoca, Verifi or a reseller: finds the sale, and if the merchant has turned on fast
 * refunds for alerts (up to an amount), refunds it at once so the cardholder's bank cancels the dispute. The answer
 * goes back to the alert provider in the same call. Idempotent by provider + alert ID; the refund uses the alert as
 * its idempotency key, so a repeated alert can never refund twice.
 *
 * Verifi RDR alerts are already resolved inside Visa by the merchant's RDR rules: they are recorded, never refunded
 * again here.
 */
final class AlertResponder
{
    public const PROVIDERS = ['ethoca' => 'Mastercard Ethoca', 'verifi_cdrn' => 'Visa Verifi CDRN', 'verifi_rdr' => 'Visa Verifi RDR', 'other' => 'Other alert network'];

    public function __construct(private readonly PaymentGateway $payments) {}

    /**
     * @param  array{transaction_id?:string|null, provider_reference?:string|null, amount_minor:int, currency:string, card_last4?:string|null, transaction_date?:string|null, alert_type?:string|null}  $a
     */
    public function handle(string $provider, string $alertId, array $a, string $receivedVia, string $receivedFrom): DisputeAlert
    {
        if (! array_key_exists($provider, self::PROVIDERS)) {
            throw new \InvalidArgumentException('Unknown alert provider.');
        }
        $alertId = mb_substr(trim($alertId), 0, 100);
        if ($alertId === '') {
            throw new \InvalidArgumentException('alert_id is required.');
        }
        if ($existing = DisputeAlert::query()->where('provider', $provider)->where('provider_alert_id', $alertId)->first()) {
            return $existing;
        }

        $t = $this->match($a);
        $status = 'not_found';
        $note = null;
        $refundId = null;
        if ($t !== null) {
            $remaining = $t->captured_minor - $t->refunded_minor;
            $setting = AlertSetting::query()->where('merchant_id', $t->merchant_id)->first();
            if ($provider === 'verifi_rdr') {
                $status = 'rdr_resolved';
                $note = 'Resolved by the merchant\'s RDR rules at Visa; no refund sent from AmpliRail.';
            } elseif ($remaining <= 0) {
                $status = 'already_refunded';
            } elseif (Dispute::query()->where('transaction_id', $t->id)->exists()) {
                $status = 'chargeback_open';
            } elseif ($setting === null || ! $setting->auto_refund || $a['amount_minor'] > $setting->max_refund_minor) {
                $status = 'declined';
                $note = 'The merchant has not turned on fast refunds for this amount.';
            } else {
                $amount = min($a['amount_minor'], $remaining);
                try {
                    $o = $this->payments->refund($t->id, Money::of($amount, $t->currency), 'alert:'.$provider.':'.$alertId, 'Pre-dispute alert ('.self::PROVIDERS[$provider].')');
                    $ok = ($o->resource['status'] ?? '') === 'succeeded';
                    $refundId = isset($o->resource['id']) ? (string) $o->resource['id'] : null;
                    $note = $ok ? null : (string) ($o->resource['message'] ?? 'Refund failed at the processor.');
                } catch (\Throwable $e) {
                    $ok = false;
                    $note = mb_substr('Refund not possible: '.$e->getMessage(), 0, 255);
                }
                $status = $ok ? 'refunded' : 'refund_failed';
            }
        }

        return DB::transaction(fn () => DisputeAlert::query()->create([
            'id' => Ids::new('alr'),
            'provider' => $provider,
            'provider_alert_id' => $alertId,
            'merchant_id' => $t?->merchant_id,
            'transaction_id' => $t?->id,
            'environment' => $t->environment ?? 'live',
            'amount_minor' => $a['amount_minor'],
            'currency' => strtoupper($a['currency']),
            'card_last4' => isset($a['card_last4']) && preg_match('/^\d{4}$/', (string) $a['card_last4']) ? $a['card_last4'] : null,
            'transaction_date' => isset($a['transaction_date']) && $a['transaction_date'] !== '' ? CarbonImmutable::parse($a['transaction_date'])->toDateString() : null,
            'alert_type' => $a['alert_type'] ?? null,
            'status' => $status,
            'refund_id' => $refundId,
            'outcome_note' => $note,
            'received_via' => $receivedVia,
            'received_from' => $receivedFrom,
        ]));
    }

    /** @param array<string,mixed> $a */
    private function match(array $a): ?Transaction
    {
        $base = fn () => Transaction::query()->where('status', 'approved');
        if (! empty($a['transaction_id'])) {
            return $base()->whereKey((string) $a['transaction_id'])->first();
        }
        if (! empty($a['provider_reference'])) {
            return $base()->where('provider_txn_id', (string) $a['provider_reference'])->first();
        }
        if (empty($a['card_last4']) || empty($a['transaction_date'])) {
            return null;
        }
        $day = CarbonImmutable::parse((string) $a['transaction_date']);
        $found = $base()->where('card_last4', (string) $a['card_last4'])->where('captured_minor', (int) $a['amount_minor'])
            ->where('currency', strtoupper((string) $a['currency']))
            ->where('created_at', '>=', $day->subDays(2)->startOfDay())->where('created_at', '<', $day->addDays(3)->startOfDay())
            ->limit(2)->get();

        return $found->count() === 1 ? $found->first() : null; // ambiguous matches are never refunded
    }
}
