<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use AmpliRail\Money\Money;

/**
 * The payment hot path (master document 4.5): key check and tenant context happen before this call.
 * All methods are idempotent per idempotency key and fail closed on missing tenant context.
 */
interface PaymentGateway
{
    public function charge(ChargeCommand $command): PaymentOutcome;

    public function capture(string $transactionId, ?Money $amount, string $idempotencyKey): PaymentOutcome;

    public function void(string $transactionId, string $idempotencyKey): PaymentOutcome;

    public function refund(string $transactionId, Money $amount, string $idempotencyKey, ?string $reason = null): PaymentOutcome;
}
