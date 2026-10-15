<?php

declare(strict_types=1);

namespace Modules\Identity\Application;

use App\Support\Actor;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Identity\Contracts\ApiKeyIssuing;
use Modules\Identity\Contracts\ApiPrincipal;
use Modules\Identity\Contracts\IssuedKeyPair;
use Modules\Identity\Infrastructure\ApiKey;

/** Merchant API keys: arpub_sbx_/arsec_sbx_ (sandbox) and arpub_live_/arsec_live_ (live). Secret shown once. */
final class ApiKeyService implements ApiKeyIssuing
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function issuePair(string $tenantId, string $merchantId, string $environment, ?string $createdBy = null): IssuedKeyPair
    {
        $this->assertEnvironment($environment);
        $existingSecret = ApiKey::query()->where('tenant_id', $tenantId)->where('merchant_id', $merchantId)
            ->where('environment', $environment)->where('type', 'secret')->whereNull('revoked_at')->first();
        $existingPub = ApiKey::query()->where('tenant_id', $tenantId)->where('merchant_id', $merchantId)
            ->where('environment', $environment)->where('type', 'publishable')->whereNull('revoked_at')->first();
        if ($existingSecret !== null && $existingPub !== null) {
            return new IssuedKeyPair($existingPub->id, $existingPub->display_prefix, $existingSecret->id, $existingSecret->display_prefix, false);
        }

        $pub = $this->create($tenantId, $merchantId, 'publishable', $environment, $createdBy);
        $sec = $this->create($tenantId, $merchantId, 'secret', $environment, $createdBy);
        $this->audit->record('api_key.created', 'merchant', $merchantId, null, ['environment' => $environment, 'keys' => [$pub['model']->id, $sec['model']->id]]);

        return new IssuedKeyPair($pub['model']->id, $pub['plain'], $sec['model']->id, $sec['model']->display_prefix, true);
    }

    /** Returns the secret once, then erases the stored copy. */
    public function reveal(ApiKey $key): ?string
    {
        return DB::transaction(function () use ($key): ?string {
            /** @var ApiKey $fresh */
            $fresh = ApiKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();
            $secret = $fresh->pending_reveal;
            if ($secret === null) {
                return null;
            }
            $fresh->forceFill(['pending_reveal' => null])->save();
            $this->audit->record('api_key.revealed', 'api_key', $fresh->id, null, null);

            return $secret;
        });
    }

    /** Revokes the key and issues a replacement of the same type; returns the new plain secret once. */
    public function roll(ApiKey $key, Actor $actor): string
    {
        return DB::transaction(function () use ($key, $actor): string {
            $key->forceFill(['revoked_at' => now(), 'pending_reveal' => null])->save();
            $new = $this->create($key->tenant_id, $key->merchant_id, $key->type, $key->environment, $actor->id, reveal: false);
            $this->audit->record('api_key.rotated', 'api_key', $key->id, null, ['replacement' => $new['model']->id], $actor);

            return $new['plain'];
        });
    }

    public function authenticate(string $bearer, ?string $ip): ?ApiPrincipal
    {
        if (! preg_match('/^arsec_(sbx|live)_[A-Za-z0-9]{32,64}$/', $bearer)) {
            return null;
        }
        $key = ApiKey::query()->where('key_hash', hash('sha256', $bearer))->where('type', 'secret')->whereNull('revoked_at')->first();
        if ($key === null) {
            return null;
        }
        $allow = $key->ip_allowlist ?? [];
        if ($allow !== [] && ($ip === null || ! in_array($ip, $allow, true))) {
            return null;
        }
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            ApiKey::query()->whereKey($key->id)->update(['last_used_at' => now()]);
        }

        return new ApiPrincipal('api_key', $key->id, $key->tenant_id, $key->merchant_id, $key->environment, ['*']);
    }

    /** @return array{model: ApiKey, plain: string} */
    private function create(string $tenantId, string $merchantId, string $type, string $environment, ?string $createdBy, bool $reveal = true): array
    {
        $env = $environment === 'live' ? 'live' : 'sbx';
        $plain = ($type === 'secret' ? 'arsec_' : 'arpub_').$env.'_'.Str::random(40);
        $display = $type === 'secret' ? substr($plain, 0, 14).'...'.substr($plain, -4) : $plain;

        $model = ApiKey::query()->create([
            'id' => Ids::new('key'),
            'tenant_id' => $tenantId,
            'merchant_id' => $merchantId,
            'type' => $type,
            'environment' => $environment,
            'display_prefix' => $display,
            'key_hash' => hash('sha256', $plain),
            'scopes' => $type === 'secret' ? ['*'] : ['tokenize', 'confirm'],
            'ip_allowlist' => [],
            'pending_reveal' => $type === 'secret' && $reveal ? $plain : null,
            'created_by' => $createdBy,
        ]);

        return ['model' => $model, 'plain' => $plain];
    }

    private function assertEnvironment(string $environment): void
    {
        if (! in_array($environment, ['sandbox', 'live'], true)) {
            throw new \InvalidArgumentException('environment must be sandbox or live');
        }
    }
}
