<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\ApiException;
use App\Support\Crypto\FieldEncryption;
use App\Support\Idempotency;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Jobs\RequestRiskAssessment;
use Modules\Tenancy\Contracts\OrgDirectory;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * AR-168. One idempotent call creates the canonical merchant (crm_deal_id preserved, immutable), moves it to submitted
 * and provisions the sandbox, all in ONE database transaction with the outbox events. Retries by Idempotency-Key or by
 * crm_deal_id return the existing merchant, never a duplicate. The same service backs manual "Add merchant".
 */
final class HandoffService
{
    public function __construct(
        private readonly TenantScope $tenants,
        private readonly Idempotency $idempotency,
        private readonly OrgDirectory $orgs,
        private readonly MerchantLifecycle $lifecycle,
        private readonly SandboxProvisioner $sandbox,
        private readonly DocumentChecklist $checklist,
        private readonly UserDirectory $users,
        private readonly FieldEncryption $crypto,
    ) {}

    /**
     * @param  array<string,mixed>  $data  validated HandoffPayload
     * @return array{0:int, 1:array<string,mixed>}
     */
    public function handle(array $data, string $idempotencyKey, string $source): array
    {
        $ctx = $this->tenants->current();
        $scope = 'merchants.handoff';
        $begin = $this->idempotency->begin($ctx->tenantId, $scope, $idempotencyKey, $data);
        if ($begin['status'] === Idempotency::REPLAY) {
            return [(int) ($begin['response_status'] ?? 200), ($begin['response'] ?? []) + ['replayed' => true]];
        }
        if ($begin['status'] === Idempotency::CONFLICT) {
            throw ApiException::conflict('idempotency_key_reused', 'This Idempotency-Key was used with a different application package.');
        }
        if ($begin['status'] === Idempotency::IN_FLIGHT) {
            throw ApiException::conflict('request_in_progress', 'This handoff is still being processed.');
        }

        try {
            $dealId = $data['crm_deal_id'] ?? null;
            if (is_string($dealId) && ($existing = Merchant::query()->where('crm_deal_id', $dealId)->first()) !== null) {
                $body = $this->resource($existing, null) + ['replayed' => true];
                $this->idempotency->complete($ctx->tenantId, $scope, $idempotencyKey, 200, $body);

                return [200, $body];
            }

            $body = DB::transaction(function () use ($data, $source, $ctx, $scope, $idempotencyKey): array {
                $merchant = $this->create($data, $source, $ctx->tenantId);
                $this->lifecycle->apply($merchant, 'application.created', ['source' => $source, 'crm_deal_id' => $merchant->crm_deal_id]);
                $merchant->refresh();
                $sandbox = $this->sandbox->provision($merchant);
                $this->users->inviteMerchantOwner($ctx->tenantId, $merchant->id, $merchant->org_path,
                    (string) $data['primary_user']['email'], (string) $data['primary_user']['name']);
                $this->checklist->evaluate($merchant->refresh());
                $body = $this->resource($merchant->refresh(), $sandbox);
                $this->idempotency->complete($ctx->tenantId, $scope, $idempotencyKey, 201, $body);
                RequestRiskAssessment::dispatch($ctx->tenantId, $merchant->id)->afterCommit();

                return $body;
            });

            return [201, $body];
        } catch (\Throwable $e) {
            $this->idempotency->release($ctx->tenantId, $scope, $idempotencyKey);
            throw $e;
        }
    }

    /** @param array<string,mixed> $d */
    private function create(array $d, string $source, string $tenantId): Merchant
    {
        $id = Ids::new('mer');
        $b = $d['business'];
        $c = $d['commerce'];
        $orgPath = $this->orgs->ensureMerchantNode($tenantId, $id, (string) $b['legal_name']);
        DB::table('merchant_index')->insert(['merchant_id' => $id, 'tenant_id' => $tenantId]);
        $channels = array_values(array_unique((array) $c['channels']));

        return Merchant::query()->create([
            'id' => $id,
            'org_node_id' => $id,
            'org_path' => $orgPath,
            'public_id' => $this->publicId(),
            'crm_deal_id' => $d['crm_deal_id'] ?? null,
            'source' => $source,
            'merchant_type' => $d['merchant_type'] ?? 'standard',
            'state' => 'draft',
            'state_changed_at' => now(),
            'legal_name' => $b['legal_name'],
            'dba' => $b['dba'] ?? null,
            'ein' => $b['ein'],
            'ein_fingerprint' => $this->crypto->fingerprint((string) $b['ein']),
            'entity_type' => $b['entity_type'],
            'years_in_business' => $b['years_in_business'] ?? null,
            'website' => $b['website'] ?? null,
            'phone' => $b['phone'] ?? null,
            'timezone' => $b['timezone'] ?? 'America/New_York',
            'business_description' => $b['description'] ?? null,
            'address' => $b['address'],
            'contacts' => $d['contacts'],
            'owners' => $d['owners'],
            'bank_token_ref' => $d['banking']['settlement_token_ref'],
            'bank_last4' => $d['banking']['last4'] ?? null,
            'mcc' => $c['mcc'],
            'mcc_secondary' => $c['mcc_secondary'] ?? null,
            'country' => $b['address']['country'],
            'region' => $b['address']['region'],
            'channels' => $channels,
            'monthly_volume_minor' => $c['monthly_volume_minor'],
            'avg_ticket_minor' => $c['avg_ticket_minor'],
            'max_ticket_minor' => $c['max_ticket_minor'],
            'currency' => $c['currency'],
            'cnp_percent' => $c['cnp_percent'] ?? (in_array('card_present', $channels, true) ? 50 : 100),
            'recurring' => (bool) ($c['recurring'] ?? in_array('recurring', $channels, true)),
            'chargeback_ratio_bps' => $c['chargeback_ratio_bps'] ?? null,
            'agent' => $d['agent'],
            'application' => [
                'products' => $c['products'],
                'refund_policy' => $c['refund_policy'] ?? null,
                'fulfillment' => $c['fulfillment'] ?? null,
                'prior_processor' => $c['prior_processor'] ?? null,
                'services' => $d['services'] ?? [],
                'crm_documents' => $d['documents'] ?? [],
            ],
            'kyb_status' => 'pending',
            'risk_profile' => ['max_ticket_minor' => (int) $c['max_ticket_minor'], 'max_per_token_per_hour' => 10],
        ]);
    }

    private function publicId(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'EDP-M-'.Ids::digits(8);
            if (! DB::table('merchants')->where('public_id', $candidate)->exists()) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not allocate a public merchant ID.');
    }

    /**
     * @param  array<string,string>|null  $sandbox
     * @return array<string,mixed>
     */
    private function resource(Merchant $m, ?array $sandbox): array
    {
        return [
            'id' => $m->id,
            'object' => 'merchant',
            'public_id' => $m->public_id,
            'crm_deal_id' => $m->crm_deal_id,
            'legal_name' => $m->legal_name,
            'state' => $m->state,
            'sandbox' => $sandbox ?? ['provisioned' => $m->sandbox_provisioned_at !== null],
            'checklist' => $this->checklist->summary($m),
            'created_at' => $m->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
