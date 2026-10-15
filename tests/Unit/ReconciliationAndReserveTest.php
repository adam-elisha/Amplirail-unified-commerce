<?php

declare(strict_types=1);

use Modules\Reconciliation\Domain\DepositMatch;
use Modules\Reserves\Domain\ReserveBalance;

it('expects sales minus refunds minus chargebacks plus wins', function (): void {
    expect(DepositMatch::expected(138455, 5000, 2500, 1000))->toBe(131955);
});

it('matches only an exact amount', function (): void {
    expect(DepositMatch::compare(131955, 131955)->status)->toBe('matched')
        ->and(DepositMatch::compare(131955, 131954)->status)->toBe('exception')
        ->and(DepositMatch::compare(131955, 131954)->differenceMinor)->toBe(-1);
});

it('reads the held reserve from the credit balance and never releases more than held', function (): void {
    expect(ReserveBalance::heldFromLedgerBalance(-6923))->toBe(6923)
        ->and(ReserveBalance::heldFromLedgerBalance(500))->toBe(0);
    ReserveBalance::assertReleasable(6923, 6924);
})->throws(InvalidArgumentException::class);
