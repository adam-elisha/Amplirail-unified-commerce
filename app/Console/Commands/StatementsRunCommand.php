<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Settlement\Application\StatementService;
use Modules\Tenancy\Contracts\OrgDirectory;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;

/** Weekly statement run: drafts for the previous Monday-Sunday unless --from/--to are given. Drafts still need Approvals to issue. */
final class StatementsRunCommand extends Command
{
    protected $signature = 'amplirail:statements:run {--from=} {--to=} {--environment=live}';

    protected $description = 'Generate draft payout statements for the period';

    public function handle(OrgDirectory $orgs, TenantScope $tenants): int
    {
        $lastMonday = CarbonImmutable::now('UTC')->startOfWeek()->subWeek();
        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from')) : $lastMonday;
        $to = $this->option('to') ? CarbonImmutable::parse((string) $this->option('to')) : $lastMonday->addDays(6);
        foreach ($orgs->tenantIds() as $tenantId) {
            $result = $tenants->run(TenantContext::system($tenantId, Actor::system('statement_run')),
                fn () => app(StatementService::class)->generate($from, $to, (string) $this->option('environment')));
            $this->line(sprintf('%s: %d drafted, %d skipped %s', $tenantId, count($result['created']), count($result['skipped']),
                $result['skipped'] === [] ? '' : json_encode($result['skipped'])));
        }

        return self::SUCCESS;
    }
}
