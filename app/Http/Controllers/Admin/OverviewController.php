<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Approvals\Infrastructure\Approval;
use Modules\Onboarding\Application\CommandCenter;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Payments\Contracts\PaymentReports;

/** Overview with the AR-173 activation SLA command center. Read-only. ?sample=1 shows clearly labelled demo data. */
final class OverviewController extends AdminController
{
    public function index(Request $request, CommandCenter $center, PaymentReports $reports): View
    {
        $sample = $request->boolean('sample');

        return view('admin.overview', [
            'cc' => $sample ? CommandCenter::sample() : $center->snapshot(),
            'volume' => $sample ? [] : $reports->volumeByConnector(30),
            'approval' => $reports->approvalRate(30),
            'activeMerchants' => Merchant::query()->where('state', 'active')->count(),
            'testMerchants' => Merchant::query()->whereIn('state', ['test_mode', 'validation'])->count(),
            'pendingApprovals' => Approval::query()->where('status', 'pending')->count(),
        ]);
    }
}
