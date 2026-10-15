<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Actor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Contracts\OrgDirectory;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;

/** Removes expired idempotency keys (24 h), service tokens and spent one-time codes. Never touches ledger or audit data. */
final class PruneCommand extends Command
{
    protected $signature = 'amplirail:prune';

    protected $description = 'Prune expired idempotency keys, service tokens and one-time codes';

    public function handle(OrgDirectory $orgs, TenantScope $tenants): int
    {
        foreach ($orgs->tenantIds() as $tenantId) {
            $tenants->run(TenantContext::system($tenantId, Actor::system('prune')),
                fn () => DB::table('idempotency_keys')->where('tenant_id', $tenantId)->where('expires_at', '<', now())->delete());
        }
        DB::table('service_tokens')->where('expires_at', '<', now()->subHour())->delete();
        DB::table('otp_challenges')->where('created_at', '<', now()->subDay())->delete();
        $this->line('pruned');

        return self::SUCCESS;
    }
}
