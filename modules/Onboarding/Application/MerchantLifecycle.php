<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\Actor;
use App\Support\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Events\Contracts\Outbox;
use Modules\Onboarding\Domain\IllegalTransition;
use Modules\Onboarding\Domain\MerchantStateMachine;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\StateTransition;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * Applies onboarding events through MerchantStateMachine. Each accepted event writes, in ONE database transaction:
 * the merchant state, a merchant_state_transitions row, the outbox event (Section 11 envelope) and an audit row.
 * Replaying an already-applied event is a no-op (no duplicate events). Illegal events throw and are logged + audited.
 * merchant.live additionally requires a verified dual-control approval (AR-172): there is no other path to active.
 */
final class MerchantLifecycle
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly AuditTrail $audit,
        private readonly TenantScope $tenants,
        private readonly ApprovalRequests $approvals,
        private readonly RequestContext $request,
    ) {}

    /** @param array<string,mixed> $data */
    public function apply(Merchant $merchant, string $event, array $data = [], ?string $reason = null, ?string $approvalId = null, ?Actor $actor = null): LifecycleResult
    {
        $actor ??= $this->tenants->current()->actor;
        try {
            return DB::transaction(fn () => $this->applyLocked($merchant->id, $event, $data, $reason, $approvalId, $actor));
        } catch (IllegalTransition $e) {
            Log::warning('onboarding.illegal_transition', ['merchant_id' => $merchant->id, 'event' => $event, 'state' => $merchant->state, 'actor' => $actor->id]);
            $this->audit->record('onboarding.illegal_transition', 'merchant', $merchant->id, ['state' => $merchant->state], ['event' => $event, 'error' => $e->getMessage()], $actor);
            throw $e;
        }
    }

    /** @param array<string,mixed> $data */
    private function applyLocked(string $merchantId, string $event, array $data, ?string $reason, ?string $approvalId, Actor $actor): LifecycleResult
    {
        /** @var Merchant $m */
        $m = Merchant::query()->whereKey($merchantId)->lockForUpdate()->firstOrFail();
        $from = $m->state;

        if (MerchantStateMachine::isMilestone($event)) {
            [$allowedStates, $column] = MerchantStateMachine::milestone($event);
            if ($m->getAttribute($column) !== null) {
                return new LifecycleResult(false, $from, $from, null);
            }
            if (! in_array($from, $allowedStates, true)) {
                throw new IllegalTransition("Illegal onboarding milestone: $event in state $from.");
            }
            Merchant::asLifecycle(fn () => $m->forceFill([$column => now()])->save());
            $eventId = $this->emit($m, $event, $from, $from, $data, $actor);
            $this->audit->record('merchant.milestone', 'merchant', $m->id, null, ['event' => $event, 'event_id' => $eventId], $actor);

            return new LifecycleResult(true, $from, $from, $eventId);
        }

        if ($this->alreadyApplied($m, $event)) {
            return new LifecycleResult(false, $from, $from, null);
        }

        $to = MerchantStateMachine::target($event, $from, $m->previous_state);

        if ($event === 'merchant.live') {
            $approval = $approvalId !== null
                ? ($this->approvals->verifiedApproval($approvalId, 'go_live', $m->id) ?? $this->approvals->verifiedApproval($approvalId, 'go_live_override', $m->id))
                : null;
            if ($approval === null) {
                throw new IllegalTransition('merchant.live requires an approved, dual-controlled go-live approval.');
            }
            $data['approval_id'] = $approval->id;
            $data['approved_by'] = $approval->decidedBy;
            $data['requested_by'] = $approval->requestedBy;
        }

        $changes = ['state' => $to, 'state_changed_at' => now()];
        if (in_array($to, ['needs_info', 'suspended'], true)) {
            $changes['previous_state'] = $from;
        } elseif (in_array($from, ['needs_info', 'suspended'], true)) {
            $changes['previous_state'] = null;
        }
        $column = MerchantStateMachine::EVENT_TIMESTAMPS[$event] ?? null;
        if ($column !== null && $m->getAttribute($column) === null) {
            $changes[$column] = now();
        }
        Merchant::asLifecycle(fn () => $m->forceFill($changes)->save());

        $eventId = $this->emit($m, $event, $from, $to, $data, $actor);
        if ($event === 'application.created') {
            $this->emit($m, 'merchant.submitted', $from, $to, [], $actor);
        }
        StateTransition::query()->create([
            'merchant_id' => $m->id,
            'from_state' => $from,
            'to_state' => $to,
            'event' => $event,
            'event_id' => $eventId,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'correlation_id' => $this->request->correlationId(),
            'created_at' => now(),
        ]);
        $this->audit->record('merchant.state_changed', 'merchant', $m->id, ['state' => $from], ['state' => $to, 'event' => $event, 'reason' => $reason], $actor);

        return new LifecycleResult(true, $from, $to, $eventId);
    }

    /** The same event already produced the current state (a retry): do nothing. */
    private function alreadyApplied(Merchant $m, string $event): bool
    {
        $last = StateTransition::query()->where('merchant_id', $m->id)->orderByDesc('id')->first();

        return $last !== null && $last->event === $event && $last->to_state === $m->state
            && ! MerchantStateMachine::canApply($event, $m->state);
    }

    /** @param array<string,mixed> $data */
    private function emit(Merchant $m, string $type, string $from, string $to, array $data, Actor $actor): string
    {
        return $this->outbox->record($type, 'merchant', $m->id, array_merge(
            ['merchant_id' => $m->id, 'public_id' => $m->public_id, 'from_state' => $from, 'to_state' => $to],
            $data,
        ), $actor, 'platform', $m->org_path);
    }
}
