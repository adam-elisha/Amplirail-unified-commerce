<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Support\Amount;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Disputes\Infrastructure\AlertSetting;
use Modules\Disputes\Infrastructure\DisputeAlert;
use Modules\Tenancy\Contracts\TenantScope;

/** Merchant portal: pre-dispute alerts on my sales, and my fast-refund choice. */
final class MerchantAlertController
{
    public function __construct(private readonly TenantScope $tenants) {}

    public function index(): View
    {
        return view('merchant.disputes.alerts', [
            'rows' => DisputeAlert::query()->orderByDesc('created_at')->paginate(50),
            'setting' => AlertSetting::query()->first(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $v = $request->validate(['auto_refund' => ['nullable', 'boolean'], 'max_refund' => ['nullable', 'string', 'max:20']]);
        $ctx = $this->tenants->current();
        $merchantId = (string) $ctx->merchantId;
        AlertSetting::query()->updateOrCreate(['merchant_id' => $merchantId], [
            'id' => AlertSetting::query()->value('id') ?? Ids::new('als'),
            'auto_refund' => (bool) ($v['auto_refund'] ?? false),
            'max_refund_minor' => Amount::toMinor((string) ($v['max_refund'] ?? '0')) ?? 0,
            'updated_by' => $ctx->actor->id,
        ]);

        return back()->with('status', 'Saved.');
    }
}
