<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\AlertResponder;
use App\Support\Amount;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Disputes\Infrastructure\AlertSetting;
use Modules\Disputes\Infrastructure\DisputeAlert;
use Modules\Identity\Application\ServiceAuth;
use Modules\Identity\Infrastructure\ServiceClient;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Tenancy\Contracts\TenantScope;

/** Pre-dispute alerts: alert log, each merchant's fast-refund setting, and API access for alert partners. */
final class AlertController extends AdminController
{
    public function index(Request $request): View
    {
        $q = DisputeAlert::query()->orderByDesc('created_at');
        foreach (['status', 'provider', 'merchant_id'] as $f) {
            if (is_string($v = $request->query($f)) && $v !== '') {
                $q->where($f, $v);
            }
        }
        $rows = $q->paginate(50)->withQueryString();
        $merchants = Merchant::query()->orderBy('legal_name')->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]);
        $since = now()->subDays(30);

        return view('admin.disputes.alerts', [
            'rows' => $rows,
            'merchants' => $merchants,
            'settings' => AlertSetting::query()->get()->keyBy('merchant_id'),
            'providers' => AlertResponder::PROVIDERS,
            'clients' => ServiceClient::query()->whereJsonContains('scopes', 'alerts.write')->orderByDesc('created_at')->get(),
            'stats' => [
                'received' => DisputeAlert::query()->where('created_at', '>=', $since)->count(),
                'refunded' => DisputeAlert::query()->where('created_at', '>=', $since)->where('status', 'refunded')->count(),
                'declined' => DisputeAlert::query()->where('created_at', '>=', $since)->where('status', 'declined')->count(),
            ],
            'canManage' => $this->can($request, 'payments.refund'),
            'canKeys' => $this->can($request, 'settings.security.manage'),
            'newSecret' => session('alert_client_secret'),
        ]);
    }

    public function settings(Request $request, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'payments.refund');
        $v = $request->validate(['merchant_id' => ['required', 'string', 'max:40'], 'auto_refund' => ['nullable', 'boolean'], 'max_refund' => ['nullable', 'string', 'max:20']]);
        $m = $this->merchant($v['merchant_id']);
        $max = Amount::toMinor((string) ($v['max_refund'] ?? '0')) ?? 0;
        $s = AlertSetting::query()->updateOrCreate(['merchant_id' => $m->id], [
            'id' => AlertSetting::query()->where('merchant_id', $m->id)->value('id') ?? Ids::new('als'),
            'auto_refund' => (bool) ($v['auto_refund'] ?? false), 'max_refund_minor' => $max, 'updated_by' => $this->userId(),
        ]);
        $audit->record('alert_settings.updated', 'merchant', $m->id, null, ['auto_refund' => $s->auto_refund, 'max_refund_minor' => $max], $this->actor());

        return back()->with('status', 'Fast refund setting saved for '.$m->displayName().'.');
    }

    public function manual(Request $request, AlertResponder $alerts): RedirectResponse
    {
        $this->requirePermission($request, 'payments.refund');
        $v = $request->validate([
            'provider' => ['required', 'in:'.implode(',', array_keys(AlertResponder::PROVIDERS))],
            'alert_id' => ['required', 'string', 'max:100'],
            'transaction_id' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $minor = Amount::toMinor($v['amount']);
        if ($minor === null || $minor < 1) {
            return back()->withInput()->with('error', 'Enter the amount like 49.99.');
        }
        try {
            $a = $alerts->handle($v['provider'], $v['alert_id'], ['transaction_id' => $v['transaction_id'], 'amount_minor' => $minor, 'currency' => $v['currency']], 'manual', $this->userId());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with($a->status === 'refunded' ? 'status' : 'error', 'Alert recorded: '.str_replace('_', ' ', $a->status).'.'.($a->outcome_note ? ' '.$a->outcome_note : ''));
    }

    public function createClient(Request $request, ServiceAuth $auth, TenantScope $tenants, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'settings.security.manage');
        $v = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $created = $auth->createClient($tenants->current()->tenantId, 'Alerts: '.$v['name'], ['alerts.write']);
        $audit->record('service_client.created', 'service_client', $created['client']->id, null, ['name' => $v['name'], 'scopes' => ['alerts.write']], $this->actor());

        return back()->with('status', 'API access created for '.$v['name'].'. Copy the secret now; it is shown only once.')
            ->with('alert_client_secret', ['client_id' => $created['client']->client_id, 'secret' => $created['secret']]);
    }
}
