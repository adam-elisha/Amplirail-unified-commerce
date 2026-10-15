<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use AmpliRail\Money\Money;
use App\Support\Actor;
use App\Support\ApiException;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\ValidationRun;
use Modules\Payments\Contracts\ChargeCommand;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Webhooks\Contracts\WebhookStatus;

/**
 * Day 4 validation (test.completed): a test sale, a partial refund, an authorization + void through the real payment
 * pipeline on the merchant's sandbox MID, plus proof that a signed webhook reached the merchant. Never uses live money.
 * Live-route test transactions, when a processor requires them, are recorded by operations as evidence on the gate.
 */
final class ValidationRunner
{
    public function __construct(
        private readonly PaymentGateway $payments,
        private readonly WebhookStatus $webhooks,
        private readonly MerchantLifecycle $lifecycle,
    ) {}

    public function run(Merchant $m, Actor $actor): ValidationRun
    {
        if ($m->state !== 'test_mode') {
            throw ApiException::invalid('invalid_state', 'Validation runs in test_mode.');
        }
        $runId = Ids::new('val');
        $checks = [];

        $sale = $this->payments->charge(new ChargeCommand($m->id, 'sandbox', $runId.':sale', Money::of(100, $m->currency), 'tok_sim_approved',
            'validation-'.$runId, channel: 'validation'));
        $checks['test_sale'] = ['passed' => $sale->status() === 'approved', 'transaction_id' => $sale->id()];

        $refundOk = false;
        if ($checks['test_sale']['passed'] && $sale->id() !== null) {
            $refund = $this->payments->refund($sale->id(), Money::of(50, $m->currency), $runId.':refund', 'validation');
            $refundOk = ($refund->resource['status'] ?? null) === 'succeeded';
        }
        $checks['test_refund'] = ['passed' => $refundOk];

        $auth = $this->payments->charge(new ChargeCommand($m->id, 'sandbox', $runId.':auth', Money::of(100, $m->currency), 'tok_sim_approved',
            'validation-'.$runId.'-auth', capture: false, channel: 'validation'));
        $voidOk = false;
        if ($auth->status() === 'approved' && $auth->id() !== null) {
            $void = $this->payments->void($auth->id(), $runId.':void');
            $voidOk = ($void->resource['state'] ?? null) === 'voided';
        }
        $checks['test_void'] = ['passed' => $voidOk, 'transaction_id' => $auth->id()];
        $checks['webhook_delivery'] = ['passed' => $this->webhooks->hasSuccessfulDelivery($m->id)];

        $passed = ! in_array(false, array_column($checks, 'passed'), true);

        return DB::transaction(function () use ($m, $runId, $checks, $passed, $actor): ValidationRun {
            $run = ValidationRun::query()->create([
                'id' => $runId, 'merchant_id' => $m->id, 'status' => $passed ? 'passed' : 'failed', 'checks' => $checks,
                'created_by' => $actor->id, 'created_at' => now(),
            ]);
            if ($passed) {
                $this->lifecycle->apply($m->refresh(), 'test.completed', ['validation_run_id' => $runId], null, null, $actor);
            }

            return $run;
        });
    }

    public function latestPassed(Merchant $m): ?ValidationRun
    {
        return ValidationRun::query()->where('merchant_id', $m->id)->where('status', 'passed')->orderByDesc('created_at')->first();
    }
}
