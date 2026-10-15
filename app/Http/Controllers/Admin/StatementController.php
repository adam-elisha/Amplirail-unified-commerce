<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Export\StatementDownloads;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Settlement\Application\StatementService;
use Modules\Settlement\Infrastructure\FeeSchedule;
use Modules\Settlement\Infrastructure\PayoutStatement;
use Symfony\Component\HttpFoundation\Response;

final class StatementController extends AdminController
{
    public function index(): View
    {
        $rows = PayoutStatement::query()->orderByDesc('period_start')->orderBy('mid_label')->paginate(50);
        $names = Merchant::query()->whereIn('id', collect($rows->items())->pluck('merchant_id')->unique())->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]);
        $lastMonday = CarbonImmutable::now('UTC')->startOfWeek()->subWeek();

        return view('admin.statements.index', ['rows' => $rows, 'names' => $names, 'schedules' => FeeSchedule::query()->get(),
            'defaultFrom' => $lastMonday->toDateString(), 'defaultTo' => $lastMonday->addDays(6)->toDateString()]);
    }

    public function generate(Request $request, StatementService $statements): RedirectResponse
    {
        $d = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'environment' => ['required', 'in:live,sandbox']]);
        $result = $statements->generate(CarbonImmutable::parse((string) $d['from']), CarbonImmutable::parse((string) $d['to']), (string) $d['environment']);

        return back()->with('status', count($result['created']).' draft statement(s) created'.($result['skipped'] !== []
            ? '; skipped: '.collect($result['skipped'])->map(fn ($s) => $s['merchant_id'].' ('.$s['reason'].')')->implode(', ') : '.'));
    }

    public function submit(string $statement, StatementService $statements): RedirectResponse
    {
        $statements->requestIssue(PayoutStatement::query()->whereKey($statement)->firstOrFail(), $this->userId());

        return back()->with('status', 'Sent to Approvals. A second person issues it.');
    }

    public function download(string $statement, string $format, StatementDownloads $downloads): Response
    {
        abort_unless(in_array($format, ['csv', 'pdf', 'xlsx'], true), 404);

        return $downloads->download(PayoutStatement::query()->whereKey($statement)->firstOrFail(), $format);
    }
}
