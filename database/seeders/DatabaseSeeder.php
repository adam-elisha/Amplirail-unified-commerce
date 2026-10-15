<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Actor;
use App\Support\Ids;
use Illuminate\Database\Seeder;
use Modules\Onboarding\Infrastructure\DocumentRequirement;
use Modules\Routing\Infrastructure\EligibilityRuleSet;
use Modules\Settlement\Infrastructure\FeeSchedule;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Tenancy\Infrastructure\OrgNode;

/**
 * Reference data only (safe in every environment): the tenant organization, default document requirements, the first
 * eligibility rule set and the Test Merchant 01 fee schedule. No users, keys or merchants: create admins with
 * php artisan amplirail:admin:create and service clients with php artisan amplirail:service-client:create.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = (string) config('amplirail.default_tenant');
        OrgNode::query()->firstOrCreate(['id' => $tenantId], [
            'type' => 'organization', 'parent_id' => null, 'tenant_id' => $tenantId, 'path' => '/'.$tenantId.'/',
            'name' => 'eDataPay', 'status' => 'active',
        ]);

        app(TenantScope::class)->run(TenantContext::system($tenantId, Actor::system('seeder')), function (): void {
            $this->documentRequirements();
            $this->eligibilityRules();
            $this->feeSchedule();
        });
    }

    private function documentRequirements(): void
    {
        $rows = [
            ['government_id', 'Government ID for each owner over 25 percent', 'submitted', null],
            ['bank_letter', 'Bank letter or voided check (settlement account)', 'submitted', null],
            ['business_registration', 'Articles of incorporation or business registration', 'submitted', null],
            ['processing_statements', 'Last 3 months of processing statements', 'submitted', ['min_monthly_volume_minor' => 5000000]],
            ['financial_statements', 'Business financial statements', 'boarding', ['min_monthly_volume_minor' => 25000000]],
            ['recurring_terms', 'Subscription terms and cancellation policy', 'submitted', ['recurring' => true]],
            ['restricted_category_license', 'License for restricted category', 'submitted', ['mcc_in' => ['5993', '5122', '7995', '6051']]],
        ];
        foreach ($rows as [$type, $label, $stage, $applies]) {
            if (! DocumentRequirement::query()->where('document_type', $type)->exists()) {
                DocumentRequirement::query()->create([
                    'id' => Ids::new('drq'), 'document_type' => $type, 'label' => $label, 'stage' => $stage, 'applies_to' => $applies,
                    'active' => true, 'version' => 1, 'effective_from' => now()->subDay(),
                ]);
            }
        }
    }

    private function eligibilityRules(): void
    {
        if (EligibilityRuleSet::query()->exists()) {
            return;
        }
        // Version 1, published by the seed (later versions go through Approvals). Values are starting points for
        // Operations/Risk to confirm; they are not provider-confirmed limits.
        EligibilityRuleSet::query()->create([
            'id' => Ids::new('ers'), 'version' => 1, 'status' => 'published', 'effective_from' => now()->subDay(), 'published_at' => now(),
            'created_by' => 'seeder', 'notes' => 'Initial launch routes (to be confirmed by Operations and Risk).',
            'rules' => ['routes' => [
                [
                    'code' => 'nmi_bridge:us', 'name' => 'Card bridge US', 'connector' => 'nmi_bridge', 'priority' => 1,
                    'rules' => [
                        'countries' => ['US'], 'mcc_blocked' => ['7995', '6051'], 'mcc_restricted' => ['5967', '5993', '5122'], 'allow_restricted' => false,
                        'channels' => ['ecommerce', 'moto', 'virtual_terminal', 'payment_link', 'recurring', 'invoicing', 'mobile'],
                        'card_present' => false, 'recurring_allowed' => true, 'max_monthly_volume_minor' => 100000000, 'max_ticket_minor' => 2500000,
                        'max_chargeback_ratio_bps' => 90, 'currencies' => ['USD'], 'required_documents' => ['government_id', 'bank_letter', 'business_registration'],
                    ],
                ],
                [
                    'code' => 'gattera_bridge:eu', 'name' => 'Card bridge EU', 'connector' => 'gattera_bridge', 'priority' => 2,
                    'rules' => [
                        'countries' => ['DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'IE', 'PT', 'PL', 'CZ', 'RO', 'HU', 'SE', 'DK', 'FI', 'GR', 'BG', 'HR', 'SK', 'SI', 'LT', 'LV', 'EE', 'LU', 'MT', 'CY', 'GB'],
                        'mcc_blocked' => ['7995'], 'channels' => ['ecommerce', 'payment_link'], 'card_present' => false, 'recurring_allowed' => false,
                        'max_monthly_volume_minor' => 50000000, 'currencies' => ['EUR', 'GBP', 'PLN', 'CZK', 'RON', 'HUF', 'SEK', 'DKK'],
                        'min_reserve_bps' => 500, 'required_documents' => ['government_id', 'bank_letter', 'business_registration', 'processing_statements'],
                    ],
                ],
                [
                    'code' => 'high_risk_review', 'name' => 'Restricted category partner (manual)', 'connector' => 'nmi_bridge', 'priority' => 5,
                    'rules' => ['countries' => ['US'], 'mcc_blocked' => ['7995'], 'allow_restricted' => true, 'mcc_restricted' => ['5967', '5993', '5122', '6051'],
                        'currencies' => ['USD'], 'min_reserve_bps' => 1000, 'max_chargeback_ratio_bps' => 150,
                        'required_documents' => ['government_id', 'bank_letter', 'business_registration', 'restricted_category_license']],
                ],
            ]],
        ]);
    }

    private function feeSchedule(): void
    {
        if (FeeSchedule::query()->where('version', 'TM-2026-10-v1')->exists()) {
            return;
        }
        $path = base_path('packages/amplirail-core/config/test_merchant_fee_schedule.json');
        $definition = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        FeeSchedule::query()->create([
            'id' => Ids::new('fs'), 'version' => $definition['version'], 'currency' => $definition['currency'], 'definition' => $definition, 'status' => 'active',
        ]);
    }
}
