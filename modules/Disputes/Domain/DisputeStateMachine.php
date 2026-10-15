<?php

declare(strict_types=1);

namespace Modules\Disputes\Domain;

/**
 * Dispute lifecycle. needs_response -> under_review (merchant responded) -> won | lost.
 * needs_response -> accepted (merchant accepts the chargeback) or -> lost (deadline missed, recorded by staff).
 * Closed states (won, lost, accepted) are final; the database blocks any later change.
 */
final class DisputeStateMachine
{
    public const OPEN = ['needs_response', 'under_review'];

    public const CLOSED = ['won', 'lost', 'accepted'];

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'needs_response' => ['under_review', 'accepted', 'lost', 'won'],
        'under_review' => ['won', 'lost'],
        'won' => [],
        'lost' => [],
        'accepted' => [],
    ];

    public const CATEGORIES = [
        'fraud' => 'Fraud or not authorized',
        'not_received' => 'Product or service not received',
        'not_as_described' => 'Not as described or defective',
        'duplicate' => 'Duplicate charge',
        'credit_not_processed' => 'Refund or credit not processed',
        'subscription_cancelled' => 'Cancelled subscription still charged',
        'other' => 'Other',
    ];

    public const STAGES = ['inquiry', 'chargeback', 'pre_arbitration', 'arbitration'];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (! self::can($from, $to)) {
            throw new IllegalDisputeTransition("Dispute cannot move from $from to $to.");
        }
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }

    /**
     * Whether money is held back from the merchant in this status. An inquiry (retrieval request) moves no money;
     * every chargeback stage does until the dispute is won.
     */
    public static function fundsHeld(string $stage, string $status): bool
    {
        return $stage !== 'inquiry' && $status !== 'won';
    }
}
