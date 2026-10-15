<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\Actor;
use Modules\Approvals\Contracts\ApprovalHandler;
use Modules\Approvals\Contracts\ApprovalView;
use Modules\Identity\Contracts\ApiKeyIssuing;
use Modules\Onboarding\Infrastructure\Merchant;

/** Executes an approved go_live or go_live_override item. Registered twice (one per type). */
final class GoLiveApprovalHandler implements ApprovalHandler
{
    public function __construct(
        private readonly string $handles,
        private readonly GoLiveGate $gate,
        private readonly MerchantLifecycle $lifecycle,
        private readonly ApiKeyIssuing $keys,
    ) {}

    public function type(): string
    {
        return $this->handles;
    }

    public function execute(ApprovalView $approval, Actor $approver): array
    {
        return $this->gate->activate($approval, $approver, $this->handles === 'go_live_override', $this->lifecycle, function (Merchant $m): array {
            // Live API keys are issued only now, after the dual-controlled activation; the secret is revealed once in the portal.
            $pair = $this->keys->issuePair($m->tenant_id, $m->id, 'live');

            return ['live_publishable_key' => $pair->publishableKey, 'live_secret_key_hint' => $pair->secretKeyDisplay];
        });
    }
}
