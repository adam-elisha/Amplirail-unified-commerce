<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Actor;
use Illuminate\Console\Command;
use Modules\Tenancy\Contracts\OrgDirectory;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Webhooks\Infrastructure\WebhookDelivery;
use Modules\Webhooks\Jobs\DeliverWebhook;

final class WebhooksRetryCommand extends Command
{
    protected $signature = 'amplirail:webhooks:retry';

    protected $description = 'Queue webhook deliveries whose next attempt is due';

    public function handle(OrgDirectory $orgs, TenantScope $tenants): int
    {
        $total = 0;
        foreach ($orgs->tenantIds() as $tenantId) {
            $total += $tenants->run(TenantContext::system($tenantId, Actor::system('scheduler')), function () use ($tenantId): int {
                $due = WebhookDelivery::query()->whereIn('status', ['pending', 'failed'])->where('next_attempt_at', '<=', now())->limit(500)->pluck('id');
                foreach ($due as $id) {
                    DeliverWebhook::dispatch($tenantId, (string) $id);
                }

                return $due->count();
            });
        }
        $this->line("queued $total");

        return self::SUCCESS;
    }
}
