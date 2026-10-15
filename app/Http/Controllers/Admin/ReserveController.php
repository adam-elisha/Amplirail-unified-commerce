<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Amount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Ledger\Infrastructure\LedgerPosting;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Reserves\Application\ReserveService;
use Modules\Reserves\Domain\ReserveBalance;

/** Reserves held per merchant (from the ledger) and release requests through Approvals. */
final class ReserveController extends AdminController
{
    public function index(Request $request): View
    {
        $rows = LedgerPosting::query()->where('account', 'like', 'reserve:%')
            ->selectRaw('account, currency, environment, sum(amount_minor) as balance, max(created_at) as last_at')
            ->groupBy('account', 'currency', 'environment')->get()
            ->map(fn ($r) => [
                'merchant_id' => substr((string) $r->getAttribute('account'), 8),
                'currency' => (string) $r->getAttribute('currency'),
                'environment' => (string) $r->getAttribute('environment'),
                'held_minor' => ReserveBalance::heldFromLedgerBalance((int) $r->getAttribute('balance')),
                'last_at' => $r->getAttribute('last_at'),
            ])->filter(fn ($r) => $r['held_minor'] > 0)->values();
        $names = Merchant::query()->whereIn('id', $rows->pluck('merchant_id')->unique())->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]);

        return view('admin.reserves.index', ['rows' => $rows, 'names' => $names, 'canRequest' => $this->can($request, 'fees.manage')]);
    }

    public function release(Request $request, ReserveService $reserves): RedirectResponse
    {
        $this->requirePermission($request, 'fees.manage');
        $v = $request->validate([
            'merchant_id' => ['required', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'environment' => ['required', 'in:sandbox,live'],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $m = $this->merchant($v['merchant_id']);
        $minor = Amount::toMinor($v['amount']) ?? 0;
        try {
            $reserves->requestRelease($m->id, $m->displayName(), $v['currency'], $v['environment'], $minor, $v['reason'], $this->userId());
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Release requested. A second person with fee approval rights must approve it in Approvals.');
    }
}
