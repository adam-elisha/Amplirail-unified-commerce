<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Amount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Modules\Disputes\Application\DisputeService;
use Modules\Disputes\Contracts\DisputedTransaction;
use Modules\Disputes\Domain\DisputeStateMachine;
use Modules\Disputes\Infrastructure\Dispute;
use Modules\Events\Contracts\EventLog;
use Modules\Ledger\Contracts\Ledger;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Payments\Infrastructure\Transaction;

/** Disputes and chargebacks screen: list with deadlines, record a new case, record the outcome. */
final class DisputeController extends AdminController
{
    public function index(Request $request): View
    {
        $q = Dispute::query()->orderByRaw("case when status in ('needs_response','under_review') then 0 else 1 end")->orderBy('due_at')->orderByDesc('created_at');
        foreach (['status', 'environment', 'merchant_id', 'reason_category'] as $f) {
            if (is_string($v = $request->query($f)) && $v !== '') {
                $q->where($f, $v);
            }
        }
        $rows = $q->paginate(50)->withQueryString();
        $names = Merchant::query()->whereIn('id', collect($rows->items())->pluck('merchant_id')->unique())->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]);
        $open = Dispute::query()->whereIn('status', DisputeStateMachine::OPEN);

        return view('admin.disputes.index', [
            'rows' => $rows,
            'names' => $names,
            'categories' => DisputeStateMachine::CATEGORIES,
            'stages' => DisputeStateMachine::STAGES,
            'stats' => [
                'needs_response' => (clone $open)->where('status', 'needs_response')->count(),
                'under_review' => (clone $open)->where('status', 'under_review')->count(),
                'due_soon' => (clone $open)->where('status', 'needs_response')->whereNotNull('due_at')->where('due_at', '<=', now()->addDays(3))->count(),
            ],
            'canManage' => $this->can($request, 'payments.refund'),
            'prefill' => is_string($t = $request->query('transaction_id')) ? $t : '',
        ]);
    }

    public function show(string $dispute, EventLog $events, Ledger $ledger): View
    {
        $d = Dispute::query()->whereKey($dispute)->firstOrFail();

        return view('admin.disputes.show', [
            'd' => $d,
            'merchant' => Merchant::query()->whereKey($d->merchant_id)->first(),
            'txn' => Transaction::query()->whereKey($d->transaction_id)->first(),
            'events' => $events->forSubjects([$d->id]),
            'ledger' => $ledger->postingsForSource($d->id),
        ]);
    }

    public function store(Request $request, DisputeService $disputes): RedirectResponse
    {
        $this->requirePermission($request, 'payments.refund');
        $v = $request->validate([
            'transaction_id' => ['required', 'string', 'max:40'],
            'amount' => ['nullable', 'string', 'max:20'],
            'stage' => ['required', 'in:'.implode(',', DisputeStateMachine::STAGES)],
            'reason_category' => ['required', 'in:'.implode(',', array_keys(DisputeStateMachine::CATEGORIES))],
            'reason_code' => ['nullable', 'string', 'max:20'],
            'network_case_id' => ['required', 'string', 'max:80'],
            'due_at' => ['nullable', 'date'],
        ]);
        $t = Transaction::query()->whereKey(trim($v['transaction_id']))->first();
        if ($t === null) {
            return back()->withInput()->with('error', 'Transaction not found.');
        }
        if (! in_array($t->state, ['captured', 'partially_refunded', 'refunded'], true)) {
            return back()->withInput()->with('error', 'Only captured transactions can be disputed.');
        }
        $minor = ($v['amount'] ?? '') !== '' ? (Amount::toMinor((string) $v['amount']) ?? 0) : $t->captured_minor - $t->refunded_minor;
        try {
            $d = $disputes->open(
                new DisputedTransaction($t->id, $t->merchant_id, $t->merchant_account_id, $t->environment, $t->currency, $t->captured_minor, $t->refunded_minor),
                $minor,
                $v['stage'],
                $v['reason_category'],
                $v['reason_code'] ?? null,
                $v['network_case_id'],
                isset($v['due_at']) ? Carbon::parse($v['due_at'])->endOfDay() : now()->addDays(10)->endOfDay(),
                $this->actor(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.disputes.show', $d->id)->with('status', 'Dispute recorded. The merchant can now respond in their portal.');
    }

    public function resolve(Request $request, string $dispute, DisputeService $disputes): RedirectResponse
    {
        $this->requirePermission($request, 'payments.refund');
        $v = $request->validate(['outcome' => ['required', 'in:won,lost'], 'note' => ['nullable', 'string', 'max:2000']]);
        try {
            $disputes->resolve($dispute, $v['outcome'], $v['note'] ?? null, $this->actor());
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $v['outcome'] === 'won' ? 'Marked won. The funds are returned to the merchant balance.' : 'Marked lost.');
    }

    public function respond(Request $request, string $dispute, DisputeService $disputes): RedirectResponse
    {
        $this->requirePermission($request, 'payments.refund');
        $v = $request->validate(['response' => ['required', 'string', 'max:10000'], 'evidence' => ['nullable', 'array', 'max:20'], 'evidence.*' => ['nullable', 'string', 'max:500']]);
        try {
            $disputes->respond($dispute, $v['response'], self::evidence($v['evidence'] ?? []), $this->actor());
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Response submitted on behalf of the merchant.');
    }

    /**
     * @param  array<string, string|null>  $items
     * @return list<array{type:string, description:string}>
     */
    public static function evidence(array $items): array
    {
        $out = [];
        foreach ($items as $type => $text) {
            if (is_string($text) && trim($text) !== '') {
                $out[] = ['type' => (string) $type, 'description' => trim($text)];
            }
        }

        return $out;
    }
}
