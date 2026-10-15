<?php

declare(strict_types=1);

namespace Modules\Onboarding\Jobs;

use App\Support\Actor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Onboarding\Application\RiskReview;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;

/** Day 0: open the risk case through the RiskDecision connector (queued; external call with timeouts and retries). */
final class RequestRiskAssessment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $tenantId, public readonly string $merchantId) {}

    public function handle(TenantScope $tenants, RiskReview $risk): void
    {
        $tenants->run(TenantContext::system($this->tenantId, Actor::system('risk_worker')), function () use ($risk): void {
            $merchant = Merchant::query()->whereKey($this->merchantId)->first();
            if ($merchant !== null && $risk->latest($merchant) === null) {
                $risk->requestAssessment($merchant);
            }
        });
    }
}
