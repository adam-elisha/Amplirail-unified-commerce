<?php

declare(strict_types=1);

namespace Modules\Onboarding\Jobs;

use App\Support\Actor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Onboarding\Application\KybReview;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;

final class RunKybChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $tenantId, public readonly string $merchantId) {}

    public function handle(TenantScope $tenants, KybReview $kyb): void
    {
        $tenants->run(TenantContext::system($this->tenantId, Actor::system('kyb_worker')), function () use ($kyb): void {
            $merchant = Merchant::query()->whereKey($this->merchantId)->first();
            if ($merchant !== null && $merchant->state === 'kyb_pending') {
                $kyb->run($merchant);
            }
        });
    }
}
