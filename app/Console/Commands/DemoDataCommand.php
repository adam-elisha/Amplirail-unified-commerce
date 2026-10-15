<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AmpliRail\Money\Money;
use App\Support\Actor;
use App\Support\Ids;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Modules\Events\Application\OutboxRelay;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Application\HandoffPayload;
use Modules\Onboarding\Application\HandoffService;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Payments\Contracts\ChargeCommand;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * LOCAL / DEVELOPMENT ONLY: synthetic demo merchants at different activation stages plus simulator payments.
 * Uses the real services (handoff, checklist, payments), never real people, cards or bank data.
 */
final class DemoDataCommand extends Command
{
    protected $signature = 'amplirail:demo {--tenant=}';

    protected $description = 'Create synthetic demo merchants and simulator payments (local/development only)';

    public function handle(TenantScope $tenants, HandoffService $handoff, DocumentChecklist $checklist, PaymentGateway $payments, OutboxRelay $relay): int
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->error('Refused: demo data is only allowed in local and development environments.');

            return self::FAILURE;
        }
        $tenantId = (string) ($this->option('tenant') ?: config('amplirail.default_tenant'));
        $actor = Actor::system('demo');
        $demos = [
            ['Coastal Grill LLC', 'Coastal Grill', '5812', 3],
            ['Landmark Home Decor Inc', 'Landmark Home Decor', '5712', 1],
            ['Moes Limo Service LLC', 'Moes Limo', '4121', 0],
            ['Summit Fitness Club LLC', 'Summit Fitness', '7997', 3],
        ];
        foreach ($demos as $i => [$legal, $dba, $mcc, $docs]) {
            $tenants->set(TenantContext::system($tenantId, $actor));
            $payload = HandoffPayload::validate([
                'crm_deal_id' => 'D-DEMO-'.(10480 + $i), 'merchant_type' => 'standard',
                'business' => ['legal_name' => $legal, 'dba' => $dba, 'ein' => '12-34567'.(80 + $i), 'entity_type' => 'llc', 'website' => 'https://'.strtolower(str_replace(' ', '', $dba)).'.example',
                    'address' => ['line1' => (100 + $i).' Main St', 'city' => 'Boca Raton', 'region' => 'FL', 'postal_code' => '33432', 'country' => 'US']],
                'contacts' => [['name' => 'Demo Owner '.($i + 1), 'email' => 'owner'.($i + 1).'@demo.example']],
                'owners' => [['name' => 'Demo Owner '.($i + 1), 'ownership_percent' => 100, 'identity_ref' => 'demo-idv-'.$i]],
                'primary_user' => ['name' => 'Demo Owner '.($i + 1), 'email' => 'owner'.($i + 1).'@demo.example'],
                'banking' => ['settlement_token_ref' => 'bnk_tok_demo_'.$i, 'last4' => '000'.$i],
                'commerce' => ['mcc' => $mcc, 'products' => 'Demo products', 'monthly_volume_minor' => 3000000, 'avg_ticket_minor' => 4500,
                    'max_ticket_minor' => 50000, 'currency' => 'USD', 'channels' => ['ecommerce', 'virtual_terminal', 'payment_link']],
                'agent' => ['agent_id' => 'agt_42', 'name' => 'R. Alvarez', 'iso' => 'eData ISO'],
            ], requireDealId: true);
            [, $body] = $handoff->handle($payload, 'demo-'.$i, 'crm');
            $merchant = Merchant::query()->whereKey((string) $body['id'])->firstOrFail();
            foreach (array_slice(['government_id', 'bank_letter', 'business_registration'], 0, $docs) as $type) {
                $file = UploadedFile::fake()->createWithContent("$type.pdf", "%PDF-1.4\n% synthetic demo document\n%%EOF\n");
                $checklist->upload($merchant->refresh(), $file, $type, $actor);
            }
            foreach ([10000, 2500, 4500, 1002, 7850] as $n => $minor) {   // 1002 = simulator decline, shown on purpose
                $payments->charge(new ChargeCommand($merchant->id, 'sandbox', Ids::new('demo'), Money::of($minor, 'USD'), 'tok_sim_approved',
                    'DEMO-'.($i + 1).'-'.$n, channel: 'api', card: ['brand' => 'visa', 'last4' => '1111', 'type' => 'credit']));
            }
            $this->line("{$merchant->public_id}  {$dba}  state={$merchant->refresh()->state}");
        }
        $tenants->clear();
        $this->line('relayed '.$relay->relayAll().' events');

        return self::SUCCESS;
    }
}
