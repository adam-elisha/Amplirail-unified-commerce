<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Actor;
use App\Support\Ids;
use Connectors\Secrets\ArraySecretsResolver;
use Connectors\Secrets\SecretsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Modules\Events\Application\OutboxRelay;
use Modules\Identity\Application\ApiKeyService;
use Modules\Identity\Application\ServiceAuth;
use Modules\Identity\Domain\Totp;
use Modules\Identity\Infrastructure\ApiKey;
use Modules\Identity\Infrastructure\Membership;
use Modules\Identity\Infrastructure\User;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Tenancy\Infrastructure\OrgNode;

abstract class TestCase extends BaseTestCase
{
    public const TENANT = 'org_edatapay';

    public const OTHER_TENANT = 'org_other';

    public ArraySecretsResolver $secrets;

    /** Runs before RefreshDatabase: the suite drops every table, so only a *_test database (or SQLite) is allowed. */
    public function createApplication()
    {
        $app = parent::createApplication();
        $default = (string) $app['config']->get('database.default');
        $db = (string) $app['config']->get("database.connections.$default.database");
        if ($default !== 'sqlite' && ! str_ends_with($db, '_test')) {
            throw new \RuntimeException("Refusing to run tests against database [$db]: use a separate database whose name ends in _test.");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->secrets = new ArraySecretsResolver([
            'field-encryption-key' => base64_encode(str_repeat('k', 32)),
            'fingerprint-key' => 'test-fingerprint-key',
            'link-signing-key' => 'test-link-signing-key',
        ]);
        $this->app->instance(SecretsResolver::class, $this->secrets);
    }

    /** Seeds reference data and a second tenant for isolation tests. */
    public function seedPlatform(): void
    {
        $this->seed();
        OrgNode::query()->firstOrCreate(['id' => self::OTHER_TENANT], [
            'type' => 'organization', 'tenant_id' => self::OTHER_TENANT, 'path' => '/'.self::OTHER_TENANT.'/', 'name' => 'Other org', 'status' => 'active',
        ]);
    }

    public function asTenant(string $tenantId = self::TENANT, ?string $merchantId = null): TenantContext
    {
        $ctx = new TenantContext($tenantId, '/'.$tenantId.'/', $merchantId, Actor::system('test'));
        app(TenantScope::class)->set($ctx);

        return $ctx;
    }

    /** @return string bearer token */
    public function serviceToken(string $tenantId = self::TENANT, string $scopes = 'crm.handoff merchants.read'): string
    {
        $auth = app(ServiceAuth::class);
        $created = $auth->createClient($tenantId, 'Test CRM', explode(' ', $scopes));
        $token = $auth->issueToken($created['client']->client_id, $created['secret'], []);

        return (string) $token['access_token'];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    public function handoffPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'crm_deal_id' => 'D-'.random_int(10000, 99999),
            'merchant_type' => 'standard',
            'business' => [
                'legal_name' => 'Coastal Grill LLC', 'dba' => 'Coastal Grill', 'ein' => '12-3456789', 'entity_type' => 'llc',
                'years_in_business' => 4, 'website' => 'https://coastalgrill.example', 'phone' => '+1 561 555 0100',
                'address' => ['line1' => '100 Main St', 'city' => 'Boca Raton', 'region' => 'FL', 'postal_code' => '33432', 'country' => 'US'],
            ],
            'contacts' => [['name' => 'Ana Owner', 'email' => 'ana@coastalgrill.example', 'role' => 'owner']],
            'owners' => [['name' => 'Ana Owner', 'ownership_percent' => 100, 'identity_ref' => 'crm-idv-001']],
            'primary_user' => ['name' => 'Ana Owner', 'email' => 'ana+'.random_int(1, 999999).'@coastalgrill.example'],
            'banking' => ['settlement_token_ref' => 'bnk_tok_abc123', 'last4' => '6789'],
            'commerce' => [
                'mcc' => '5812', 'products' => 'Restaurant meals and catering', 'monthly_volume_minor' => 4000000,
                'avg_ticket_minor' => 4500, 'max_ticket_minor' => 50000, 'currency' => 'USD',
                'channels' => ['ecommerce', 'virtual_terminal', 'payment_link'],
            ],
            'agent' => ['agent_id' => 'agt_42', 'name' => 'R. Alvarez', 'iso' => 'eData ISO'],
        ], $overrides);
    }

    /** Creates a merchant through the real CRM handoff API. @return array<string,mixed> response body */
    public function handoff(array $overrides = [], string $tenantId = self::TENANT): array
    {
        $response = $this->withToken($this->serviceToken($tenantId))
            ->withHeader('Idempotency-Key', 'handoff-'.Ids::new('k'))
            ->postJson('/v1/merchants/handoff', $this->handoffPayload($overrides));
        $response->assertCreated();
        app(TenantScope::class)->clear();

        return (array) $response->json();
    }

    /** Reveals the merchant's sandbox secret key once, like the portal does. */
    public function sandboxSecret(string $merchantId, string $tenantId = self::TENANT, string $environment = 'sandbox'): string
    {
        $this->asTenant($tenantId);
        $key = ApiKey::query()->where('merchant_id', $merchantId)->where('type', 'secret')->where('environment', $environment)->whereNull('revoked_at')->firstOrFail();
        $secret = (string) app(ApiKeyService::class)->reveal($key);
        app(TenantScope::class)->clear();

        return $secret;
    }

    /** @return array{0: User, 1: string} admin user and TOTP secret */
    public function adminUser(string $role = 'owner', string $tenantId = self::TENANT, ?string $email = null): array
    {
        $secret = Totp::generateSecret();
        $user = User::query()->create([
            'id' => Ids::new('usr'), 'name' => ucfirst($role).' Tester', 'email' => $email ?? $role.'.'.Ids::new('t').'@edatapay.example',
            'password' => 'Correct-Horse-9!', 'portal' => 'admin', 'status' => 'active', 'totp_secret' => $secret, 'totp_confirmed_at' => now(),
            'failed_attempts' => 0,
        ]);
        Membership::query()->create([
            'id' => Ids::new('mbr'), 'user_id' => $user->id, 'tenant_id' => $tenantId, 'org_node_id' => $tenantId, 'org_path' => '/'.$tenantId.'/',
            'merchant_id' => null, 'role' => $role, 'status' => 'active',
        ]);

        return [$user, $secret];
    }

    public function totp(string $secret, int $offsetSteps = 0): string
    {
        return Totp::codeAt($secret, intdiv(time(), 30) + $offsetSteps);
    }

    /** Signs a user in with the second factor already passed (for screen tests). */
    public function actingAsPortal(User $user): static
    {
        return $this->actingAs($user)->withSession(['mfa_passed' => $user->id, 'last_activity' => time()]);
    }

    public function relay(): int
    {
        app(TenantScope::class)->clear();

        return app(OutboxRelay::class)->relayAll();
    }

    public function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
