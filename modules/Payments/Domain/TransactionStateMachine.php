<?php

declare(strict_types=1);

namespace Modules\Payments\Domain;

/**
 * Transaction lifecycle (master document 6): authorized -> captured -> (partially_)refunded; voided.
 * Terminal outcomes from the first attempt: declined, blocked, failed, unknown (reconcile, never blind retry).
 */
final class TransactionStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'authorized' => ['captured', 'voided'],
        'captured' => ['voided', 'partially_refunded', 'refunded'],
        'partially_refunded' => ['partially_refunded', 'refunded'],
    ];

    public static function assert(string $from, string $to): void
    {
        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw new IllegalTransactionTransition("Illegal transaction transition $from -> $to.");
        }
    }

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }
}
