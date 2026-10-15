<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Disputes\Domain\DisputeStateMachine;
use Modules\Disputes\Infrastructure\Dispute;
use Modules\Onboarding\Infrastructure\Merchant;

/** Chargeback outcomes: win rate and money recovered by reason and by merchant, over a chosen period. */
final class DisputeAnalyticsController extends AdminController
{
    public function index(Request $request): View
    {
        $days = in_array((int) $request->query('days'), [30, 90, 180, 365], true) ? (int) $request->query('days') : 90;
        $base = Dispute::query()->where('stage', '!=', 'inquiry')->where('created_at', '>=', now()->subDays($days));
        $group = fn (string $col) => (clone $base)->selectRaw("$col as k, currency, count(*) as total,
              sum(case when status = 'won' then 1 else 0 end) as won,
              sum(case when status in ('lost','accepted') then 1 else 0 end) as lost,
              sum(case when status in ('needs_response','under_review') then 1 else 0 end) as open,
              sum(amount_minor) as disputed, sum(case when status = 'won' then amount_minor else 0 end) as recovered")
            ->groupBy($col, 'currency')->orderByDesc('total')->get();
        $byMerchant = $group('merchant_id');

        return view('admin.disputes.analytics', [
            'days' => $days,
            'byReason' => $group('reason_category'),
            'byMerchant' => $byMerchant,
            'names' => Merchant::query()->whereIn('id', $byMerchant->pluck('k'))->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]),
            'categories' => DisputeStateMachine::CATEGORIES,
            'responded' => (clone $base)->whereNotNull('responded_at')->count(),
            'total' => (clone $base)->count(),
        ]);
    }
}
