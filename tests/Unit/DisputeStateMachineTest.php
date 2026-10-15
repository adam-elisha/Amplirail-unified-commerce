<?php

declare(strict_types=1);

use Modules\Disputes\Domain\DisputeStateMachine;
use Modules\Disputes\Domain\IllegalDisputeTransition;

it('allows the normal dispute paths', function (): void {
    expect(DisputeStateMachine::can('needs_response', 'under_review'))->toBeTrue()
        ->and(DisputeStateMachine::can('under_review', 'won'))->toBeTrue()
        ->and(DisputeStateMachine::can('under_review', 'lost'))->toBeTrue()
        ->and(DisputeStateMachine::can('needs_response', 'accepted'))->toBeTrue();
});

it('never reopens a closed dispute', function (string $closed): void {
    foreach (['needs_response', 'under_review', 'won', 'lost', 'accepted'] as $to) {
        expect(DisputeStateMachine::can($closed, $to))->toBeFalse();
    }
})->with(['won', 'lost', 'accepted']);

it('rejects accepting after evidence was submitted', function (): void {
    DisputeStateMachine::assert('under_review', 'accepted');
})->throws(IllegalDisputeTransition::class);

it('holds funds for chargebacks but not inquiries or wins', function (): void {
    expect(DisputeStateMachine::fundsHeld('chargeback', 'needs_response'))->toBeTrue()
        ->and(DisputeStateMachine::fundsHeld('inquiry', 'needs_response'))->toBeFalse()
        ->and(DisputeStateMachine::fundsHeld('chargeback', 'won'))->toBeFalse();
});
