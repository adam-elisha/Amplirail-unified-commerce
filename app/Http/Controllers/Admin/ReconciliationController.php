<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Amount;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Reconciliation\Application\ReconciliationService;
use Modules\Reconciliation\Contracts\DepositAccount;
use Modules\Reconciliation\Infrastructure\ProcessorDeposit;
use Modules\Routing\Infrastructure\MerchantAccount;

/** Reconciliation: record or import processor deposits per MID, see matched and exception rows, explain exceptions. */
final class ReconciliationController extends AdminController
{
    public function index(Request $request): View
    {
        $q = ProcessorDeposit::query()->orderByRaw("case when status = 'exception' then 0 else 1 end")->orderByDesc('deposit_date')->orderByDesc('created_at');
        foreach (['status', 'environment', 'merchant_id', 'merchant_account_id'] as $f) {
            if (is_string($v = $request->query($f)) && $v !== '') {
                $q->where($f, $v);
            }
        }
        $rows = $q->paginate(50)->withQueryString();
        $names = Merchant::query()->whereIn('id', collect($rows->items())->pluck('merchant_id')->unique())->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]);
        $accounts = MerchantAccount::query()->orderBy('label')->get();
        $accountMerchants = Merchant::query()->whereIn('id', $accounts->pluck('merchant_id')->unique())->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]);

        return view('admin.reconciliation.index', [
            'rows' => $rows,
            'names' => $names,
            'accounts' => $accounts,
            'accountMerchants' => $accountMerchants,
            'exceptions' => ProcessorDeposit::query()->where('status', 'exception')->count(),
            'canResolve' => $this->can($request, 'recon.resolve'),
        ]);
    }

    public function store(Request $request, ReconciliationService $recon): RedirectResponse
    {
        $this->requirePermission($request, 'recon.resolve');
        $v = $request->validate([
            'merchant_account_id' => ['required', 'string', 'max:40'],
            'deposit_date' => ['required', 'date'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:20'],
            'reference' => ['required', 'string', 'max:120'],
        ]);
        $acc = MerchantAccount::query()->whereKey($v['merchant_account_id'])->first();
        if ($acc === null) {
            return back()->withInput()->with('error', 'MID not found.');
        }
        $minor = Amount::toMinor($v['amount']);
        if ($minor === null) {
            return back()->withInput()->with('error', 'Enter the amount like 1234.56.');
        }
        try {
            $d = $recon->record($this->depositAccount($acc), CarbonImmutable::parse($v['deposit_date']), CarbonImmutable::parse($v['period_start']),
                CarbonImmutable::parse($v['period_end']), $minor, $v['reference'], 'manual', $this->actor());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with($d->status === 'matched' ? 'status' : 'error', $d->status === 'matched'
            ? 'Deposit recorded and matched.'
            : 'Deposit recorded with a difference of '.money($d->difference_minor, $d->currency).'. Please review the exception.');
    }

    /** CSV columns (header row required): mid, deposit_date, period_start, period_end, amount, reference. */
    public function import(Request $request, ReconciliationService $recon): RedirectResponse
    {
        $this->requirePermission($request, 'recon.resolve');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $lines = preg_split('/\r\n|\n|\r/', trim((string) file_get_contents((string) $request->file('file')?->getRealPath()))) ?: [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv((string) array_shift($lines)));
        $need = ['mid', 'deposit_date', 'period_start', 'period_end', 'amount', 'reference'];
        if (array_diff($need, $header) !== []) {
            return back()->with('error', 'The CSV needs these columns: '.implode(', ', $need).'.');
        }
        if (count($lines) > 500) {
            return back()->with('error', 'Please import at most 500 rows at a time.');
        }
        $ok = 0;
        $exceptions = 0;
        $errors = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = array_combine($header, array_pad(str_getcsv($line), count($header), ''));
            $mid = trim((string) $row['mid']);
            $acc = MerchantAccount::query()->where(fn ($w) => $w->whereKey($mid)->orWhere('label', $mid)->orWhere('provider_mid', $mid))->first();
            $minor = Amount::toMinor((string) $row['amount']);
            if ($acc === null || $minor === null) {
                $errors[] = 'Row '.($i + 2).': '.($acc === null ? "MID $mid not found" : 'bad amount');

                continue;
            }
            try {
                $d = $recon->record($this->depositAccount($acc), CarbonImmutable::parse((string) $row['deposit_date']), CarbonImmutable::parse((string) $row['period_start']),
                    CarbonImmutable::parse((string) $row['period_end']), $minor, (string) $row['reference'], 'csv', $this->actor());
                $ok++;
                $exceptions += $d->status === 'exception' ? 1 : 0;
            } catch (\Throwable $e) {
                $errors[] = 'Row '.($i + 2).': '.($e instanceof \InvalidArgumentException ? $e->getMessage() : 'could not be read');
            }
        }
        $msg = "Imported $ok deposits ($exceptions with differences).".($errors !== [] ? ' Skipped: '.implode('; ', array_slice($errors, 0, 10)) : '');

        return back()->with($errors === [] && $exceptions === 0 ? 'status' : 'error', $msg);
    }

    public function resolve(Request $request, string $deposit, ReconciliationService $recon): RedirectResponse
    {
        $this->requirePermission($request, 'recon.resolve');
        $v = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        try {
            $recon->resolve($deposit, $v['note'], $this->actor());
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Exception explained and closed.');
    }

    private function depositAccount(MerchantAccount $acc): DepositAccount
    {
        return new DepositAccount($acc->id, $acc->merchant_id, $acc->environment, $acc->currency, $acc->label);
    }
}
