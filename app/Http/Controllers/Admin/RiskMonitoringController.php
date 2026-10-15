<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\VampMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Monitoring\Domain\VampCalculator;
use Modules\Onboarding\Infrastructure\Merchant;

/** Risk: Visa fraud and dispute monitoring (VAMP) per merchant and portfolio, and TC40 / SAFE file import. */
final class RiskMonitoringController extends AdminController
{
    public function index(Request $request, VampMonitor $vamp): View
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) === 1
            ? CarbonImmutable::parse($request->query('month').'-01') : CarbonImmutable::now()->startOfMonth();
        $env = $request->query('environment') === 'sandbox' ? 'sandbox' : 'live';
        $region = $request->query('region') === 'other' ? 'other' : 'us_ca_eu_ap';
        $data = $vamp->month($month, $env, $region);
        $names = Merchant::query()->whereIn('id', array_column($data['rows'], 'merchant_id'))->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]);

        return view('admin.risk.vamp', $data + [
            'names' => $names, 'month' => $month, 'env' => $env, 'region' => $region,
            'threshold' => VampCalculator::MERCHANT_THRESHOLD_BPS[$region], 'canImport' => $this->can($request, 'risk.decide'),
        ]);
    }

    public function import(Request $request, VampMonitor $vamp): RedirectResponse
    {
        $this->requirePermission($request, 'risk.decide');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']]);
        $lines = preg_split('/\r\n|\n|\r/', trim((string) file_get_contents((string) $request->file('file')?->getRealPath()))) ?: [];
        $r = $vamp->import($lines, $this->userId());
        $msg = "Imported {$r['imported']} fraud reports.".($r['errors'] !== [] ? ' Skipped: '.implode('; ', array_slice($r['errors'], 0, 10)) : '');

        return back()->with($r['errors'] === [] ? 'status' : 'error', $msg);
    }
}
