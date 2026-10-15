<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Actor;
use Illuminate\Console\Command;
use Modules\Identity\Application\UserAdministration;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Tenancy\Infrastructure\OrgNode;

/**
 * Creates an admin console user and prints a one-time set-password link. The user enrolls an authenticator app at
 * first sign-in. Run by the Platform Owner on the server; never paste the link into chat or tickets.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'amplirail:admin:create {email} {name} {--role=owner : platform_owner|owner|operations|finance|underwriter|support_agent|developer|read_only} {--tenant=}';

    protected $description = 'Invite an admin console user (prints a one-time set-password link)';

    public function handle(UserAdministration $users, TenantScope $tenants): int
    {
        $tenantId = (string) ($this->option('tenant') ?: config('amplirail.default_tenant'));
        $role = (string) $this->option('role');
        if (config("amplirail_roles.roles.$role.portal") !== 'admin') {
            $this->error("Unknown admin role $role");

            return self::FAILURE;
        }
        $org = OrgNode::query()->whereKey($tenantId)->where('type', 'organization')->first();
        if ($org === null) {
            $this->error("Tenant $tenantId not found. Run php artisan db:seed first.");

            return self::FAILURE;
        }
        $result = $tenants->run(TenantContext::system($tenantId, Actor::system('cli')),
            fn () => $users->invite($tenantId, $org->id, $org->path, null, (string) $this->argument('email'), (string) $this->argument('name'), $role));
        $url = rtrim((string) config('amplirail.public_urls.admin'), '/').'/admin/invitation/'.$result['token'];
        $this->info("Invited {$result['user']->email} as $role.");
        $this->line('One-time set-password link (expires in '.config('amplirail.auth.invitation_ttl_hours').' hours):');
        $this->line($url);

        return self::SUCCESS;
    }
}
