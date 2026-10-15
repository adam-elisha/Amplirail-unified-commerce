<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Identity\Application\ServiceAuth;

/** Creates an OAuth2 client for an internal service (e.g. the Fintech CRM). Store the secret in AWS Secrets Manager. */
final class CreateServiceClientCommand extends Command
{
    protected $signature = 'amplirail:service-client:create {name} {--scopes=crm.handoff,merchants.read} {--tenant=}';

    protected $description = 'Create a service client (client credentials); the secret is printed once';

    public function handle(ServiceAuth $auth): int
    {
        $tenantId = (string) ($this->option('tenant') ?: config('amplirail.default_tenant'));
        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('scopes')))));
        $allowed = ['crm.handoff', 'merchants.read', 'risk.events'];
        if (array_diff($scopes, $allowed) !== []) {
            $this->error('Allowed scopes: '.implode(', ', $allowed));

            return self::FAILURE;
        }
        $created = $auth->createClient($tenantId, (string) $this->argument('name'), $scopes);
        $this->info('client_id: '.$created['client']->client_id);
        $this->line('client_secret (shown once, store it in AWS Secrets Manager): '.$created['secret']);

        return self::SUCCESS;
    }
}
