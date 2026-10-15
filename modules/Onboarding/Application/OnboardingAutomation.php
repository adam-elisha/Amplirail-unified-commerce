<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use Modules\Events\Contracts\EventConsumer;
use Modules\Onboarding\Jobs\RunKybChecks;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * Event-driven automation so no step depends on someone remembering (4-Day design principle):
 *   documents.complete -> run KYB checks (queued)
 * Processor eligibility is evaluated when the underwriting decision is recorded (ProcessorBoarding::evaluateEligibility).
 */
final class OnboardingAutomation implements EventConsumer
{
    public function __construct(private readonly TenantScope $tenants) {}

    public function name(): string
    {
        return 'onboarding_automation';
    }

    public function handles(string $eventType): bool
    {
        return $eventType === 'documents.complete';
    }

    public function handle(array $envelope): void
    {
        $merchantId = $envelope['subject']['id'] ?? null;
        if (is_string($merchantId)) {
            RunKybChecks::dispatch($this->tenants->current()->tenantId, $merchantId)->afterCommit();
        }
    }
}
