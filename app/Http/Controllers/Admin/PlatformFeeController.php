<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Amount;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Ledger\Infrastructure\LedgerPosting;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\PlatformFees\Domain\PlatformFee;
use Modules\PlatformFees\Infrastructure\PlatformFeeRule;

/** Platform fees: a partner's markup on a merchant's sales, taken on payout statements and owed to the partner. */
final class PlatformFeeController extends AdminController
{
    public function index(Request $request): View
    {
        $earnings = LedgerPosting::query()->where('account', 'like', 'partner_payable:%')
            ->selectRaw('account, currency, environment, sum(amount_minor) as balance')->groupBy('account', 'currency', 'environment')->get()
            ->map(fn ($r) => ['partner_key' => substr((string) $r->getAttribute('account'), 16), 'currency' => (string) $r->getAttribute('currency'),
                'environment' => (string) $r->getAttribute('environment'), 'owed_minor' => max(0, -(int) $r->getAttribute('balance'))]);

        return view('admin.routing2.fees', [
            'rules' => PlatformFeeRule::query()->orderBy('partner_name')->get(),
            'earnings' => $earnings,
            'merchants' => Merchant::query()->orderBy('legal_name')->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]),
            'canEdit' => $this->can($request, 'fees.manage'),
        ]);
    }

    public function store(Request $request, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'fees.manage');
        $v = $request->validate([
            'merchant_id' => ['required', 'string', 'max:40'], 'partner_name' => ['required', 'string', 'max:120'],
            'partner_key' => ['required', 'string', 'max:60'], 'percent' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'fixed' => ['nullable', 'string', 'max:20'], 'currency' => ['required', 'string', 'size:3'],
        ]);
        $key = strtolower(trim($v['partner_key']));
        if (! PlatformFee::validKey($key)) {
            return back()->withInput()->with('error', 'The partner code uses lower-case letters, digits and underscores (e.g. acme_pay).');
        }
        $m = $this->merchant($v['merchant_id']);
        $bps = (int) round(((float) ($v['percent'] ?? 0)) * 100);
        $fixed = ($v['fixed'] ?? '') === '' ? 0 : (Amount::toMinor((string) $v['fixed']) ?? -1);
        if ($fixed < 0 || ($bps === 0 && $fixed === 0)) {
            return back()->withInput()->with('error', 'Enter a percentage, a fixed amount per sale, or both.');
        }
        $r = PlatformFeeRule::query()->create([
            'id' => Ids::new('pfr'), 'merchant_id' => $m->id, 'partner_key' => $key, 'partner_name' => trim($v['partner_name']),
            'percent_bps' => $bps, 'fixed_minor' => $fixed, 'currency' => strtoupper($v['currency']), 'status' => 'active', 'created_by' => $this->userId(),
        ]);
        $audit->record('platform_fee_rule.created', 'merchant', $m->id, null, $r->toArray(), $this->actor());

        return back()->with('status', 'Platform fee added. It applies from the next payout statement; the merchant\'s fee agreement must cover it.');
    }

    public function toggle(Request $request, string $rule, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'fees.manage');
        $r = PlatformFeeRule::query()->whereKey($rule)->firstOrFail();
        $r->forceFill(['status' => $r->status === 'active' ? 'paused' : 'active'])->save();
        $audit->record('platform_fee_rule.'.$r->status, 'merchant', $r->merchant_id, null, ['rule' => $r->id], $this->actor());

        return back()->with('status', 'Platform fee '.$r->status.'.');
    }
}
