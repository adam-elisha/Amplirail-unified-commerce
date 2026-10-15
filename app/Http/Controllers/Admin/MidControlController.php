<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Amount;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\RoutePolicy\Infrastructure\MidControl;
use Modules\Routing\Infrastructure\MerchantAccount;

/** MID management: every MID with today's and this month's volume, caps, utilization and pause/resume. */
final class MidControlController extends AdminController
{
    public function index(Request $request): View
    {
        $mids = MerchantAccount::query()->orderBy('merchant_id')->orderBy('priority')->get();
        $controls = MidControl::query()->get()->keyBy('merchant_account_id');
        $vol = DB::table('transactions')->where('status', 'approved')->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('merchant_account_id, coalesce(sum(captured_minor),0) as month, coalesce(sum(case when created_at >= ? then captured_minor else 0 end),0) as today', [now()->startOfDay()])
            ->groupBy('merchant_account_id')->get()->keyBy('merchant_account_id');

        return view('admin.routing2.mids', [
            'mids' => $mids, 'controls' => $controls, 'vol' => $vol,
            'names' => Merchant::query()->whereIn('id', $mids->pluck('merchant_id')->unique())->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]),
            'canEdit' => $this->can($request, 'merchants.limits.edit'),
        ]);
    }

    public function update(Request $request, string $mid, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'merchants.limits.edit');
        $acc = MerchantAccount::query()->whereKey($mid)->firstOrFail();
        $v = $request->validate(['monthly_cap' => ['nullable', 'string', 'max:20'], 'daily_cap' => ['nullable', 'string', 'max:20'], 'paused' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $monthly = ($v['monthly_cap'] ?? '') === '' ? null : Amount::toMinor((string) $v['monthly_cap']);
        $daily = ($v['daily_cap'] ?? '') === '' ? null : Amount::toMinor((string) $v['daily_cap']);
        if ((($v['monthly_cap'] ?? '') !== '' && $monthly === null) || (($v['daily_cap'] ?? '') !== '' && $daily === null)) {
            return back()->with('error', 'Enter caps like 250000.00, or leave them empty for no cap.');
        }
        $before = MidControl::query()->where('merchant_account_id', $acc->id)->first()?->toArray();
        $c = MidControl::query()->updateOrCreate(['merchant_account_id' => $acc->id], [
            'id' => $before['id'] ?? Ids::new('midc'), 'merchant_id' => $acc->merchant_id, 'monthly_cap_minor' => $monthly, 'daily_cap_minor' => $daily,
            'paused' => (bool) ($v['paused'] ?? false), 'note' => $v['note'] ?? null, 'updated_by' => $this->userId(),
        ]);
        $audit->record('mid_control.updated', 'merchant_account', $acc->id, $before, $c->toArray(), $this->actor());

        return back()->with('status', $acc->label.' saved'.($c->paused ? ' and paused: new payments skip this MID.' : '.'));
    }
}
