<?php

declare(strict_types=1);

namespace Modules\Onboarding\Domain;

/**
 * The ONE authoritative onboarding state machine (AR-171, master document 6 as amended by ADR-0004 / V3.7.1).
 * UI, jobs and connectors may request transitions; none may bypass this table.
 *
 * States: draft -> submitted -> kyb_pending -> underwriting -> approved -> boarding -> test_mode -> validation -> active
 * Side states: needs_info, manual_review, declined, suspended, closed.
 *
 * Milestone events (processor.approved, mid.created, merchant.sandbox_provisioned) are recorded without a state change
 * and only once per merchant.
 */
final class MerchantStateMachine
{
    public const STATES = [
        'draft', 'submitted', 'kyb_pending', 'underwriting', 'approved', 'boarding', 'test_mode', 'validation', 'active',
        'needs_info', 'manual_review', 'declined', 'suspended', 'closed',
    ];

    /** The 12 lifecycle events from the 4-Day Activation design (AR-171), plus merchant.submitted from AR-168. */
    public const LIFECYCLE_EVENTS = [
        'application.created', 'merchant.sandbox_provisioned', 'documents.complete', 'kyb.passed', 'risk.approved',
        'processor.submitted', 'processor.more_info_required', 'processor.approved', 'mid.created', 'credentials.created',
        'test.completed', 'merchant.live',
    ];

    /**
     * event => [allowed from-states => to-state]. "*resume" returns to previous_state.
     *
     * @var array<string, array<string,string>>
     */
    private const TRANSITIONS = [
        'application.created' => ['draft' => 'submitted'],
        'documents.complete' => ['submitted' => 'kyb_pending', 'needs_info' => '*resume'],
        'merchant.needs_info' => ['submitted' => 'needs_info', 'kyb_pending' => 'needs_info', 'underwriting' => 'needs_info'],
        'kyb.passed' => ['kyb_pending' => 'underwriting', 'manual_review' => 'underwriting'],
        'kyb.review_required' => ['kyb_pending' => 'manual_review'],
        'risk.approved' => ['underwriting' => 'approved', 'manual_review' => 'approved'],
        'risk.manual_review' => ['underwriting' => 'manual_review'],
        'risk.declined' => ['underwriting' => 'declined', 'manual_review' => 'declined', 'kyb_pending' => 'declined'],
        'processor.submitted' => ['approved' => 'boarding'],
        'processor.more_info_required' => ['boarding' => 'needs_info'],
        'credentials.created' => ['boarding' => 'test_mode'],
        'test.completed' => ['test_mode' => 'validation'],
        'merchant.live' => ['validation' => 'active'],
        'merchant.suspended' => [
            'submitted' => 'suspended', 'kyb_pending' => 'suspended', 'underwriting' => 'suspended', 'approved' => 'suspended',
            'boarding' => 'suspended', 'test_mode' => 'suspended', 'validation' => 'suspended', 'active' => 'suspended',
            'needs_info' => 'suspended', 'manual_review' => 'suspended',
        ],
        'merchant.reinstated' => ['suspended' => '*resume'],
        'merchant.closed' => [
            'draft' => 'closed', 'submitted' => 'closed', 'kyb_pending' => 'closed', 'underwriting' => 'closed', 'approved' => 'closed',
            'boarding' => 'closed', 'test_mode' => 'closed', 'validation' => 'closed', 'active' => 'closed', 'needs_info' => 'closed',
            'manual_review' => 'closed', 'declined' => 'closed', 'suspended' => 'closed',
        ],
    ];

    /** Milestones: event => [states in which it may be recorded, merchant timestamp column that makes it once-only]. */
    private const MILESTONES = [
        'merchant.sandbox_provisioned' => [['submitted', 'needs_info', 'kyb_pending', 'underwriting', 'manual_review', 'approved', 'boarding', 'test_mode', 'validation'], 'sandbox_provisioned_at'],
        'processor.approved' => [['boarding'], 'processor_approved_at'],
        'mid.created' => [['boarding'], 'mid_created_at'],
    ];

    /** Timestamp column set when a transition event first happens. */
    public const EVENT_TIMESTAMPS = [
        'documents.complete' => 'documents_completed_at',
        'kyb.passed' => 'kyb_passed_at',
        'risk.approved' => 'risk_approved_at',
        'processor.submitted' => 'processor_submitted_at',
        'credentials.created' => 'credentials_created_at',
        'test.completed' => 'test_completed_at',
        'merchant.live' => 'live_at',
    ];

    public static function isMilestone(string $event): bool
    {
        return isset(self::MILESTONES[$event]);
    }

    /** @return array{0: list<string>, 1: string} */
    public static function milestone(string $event): array
    {
        return self::MILESTONES[$event] ?? throw new IllegalTransition("Unknown milestone $event");
    }

    /**
     * Resolves the target state or throws.
     *
     * @throws IllegalTransition
     */
    public static function target(string $event, string $from, ?string $previous): string
    {
        $to = self::TRANSITIONS[$event][$from] ?? null;
        if ($to === null) {
            throw new IllegalTransition("Illegal onboarding transition: $event from $from.");
        }
        if ($to === '*resume') {
            if ($previous === null || ! in_array($previous, self::STATES, true) || in_array($previous, ['suspended', 'needs_info', 'closed', 'declined'], true)) {
                throw new IllegalTransition("Cannot resume from $from: no valid previous state.");
            }
            // Documents completed while the application was still at submitted move it forward, not back.
            if ($event === 'documents.complete' && $previous === 'submitted') {
                return 'kyb_pending';
            }

            return $previous;
        }

        return $to;
    }

    public static function canApply(string $event, string $from): bool
    {
        return isset(self::TRANSITIONS[$event][$from]);
    }

    /** @return list<string> */
    public static function transitionEvents(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /** @return array<string,string> */
    public static function transitionsFor(string $event): array
    {
        return self::TRANSITIONS[$event] ?? [];
    }

    /** Ordered progress steps for the Merchant 360 bar. */
    public const PROGRESS = [
        'submitted' => 'Identity', 'kyb_pending' => 'KYB / KYC', 'underwriting' => 'Risk review', 'approved' => 'Processor eligibility',
        'boarding' => 'MID provisioned', 'test_mode' => 'Credentials', 'validation' => 'Validation', 'active' => 'Live',
    ];
}
