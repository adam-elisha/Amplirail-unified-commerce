<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Admin\DisputeController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Disputes\Application\DisputeService;
use Modules\Disputes\Domain\DisputeStateMachine;
use Modules\Disputes\Infrastructure\Dispute;
use Modules\Events\Contracts\EventLog;
use Modules\Payments\Infrastructure\Transaction;
use Modules\Tenancy\Contracts\TenantScope;

/** Merchant portal disputes: see open cases and deadlines, submit a response with evidence, or accept. */
final class MerchantDisputeController
{
    public function __construct(private readonly TenantScope $tenants) {}

    public function index(Request $request): View
    {
        $q = Dispute::query()->where('environment', $this->env())
            ->orderByRaw("case when status in ('needs_response','under_review') then 0 else 1 end")->orderBy('due_at')->orderByDesc('created_at');
        if (is_string($s = $request->query('status')) && $s !== '') {
            $q->where('status', $s);
        }

        return view('merchant.disputes.index', ['rows' => $q->paginate(50)->withQueryString(), 'categories' => DisputeStateMachine::CATEGORIES]);
    }

    public function show(string $dispute, EventLog $events): View
    {
        $d = Dispute::query()->where('environment', $this->env())->whereKey($dispute)->firstOrFail();

        return view('merchant.disputes.show', [
            'd' => $d,
            'txn' => Transaction::query()->whereKey($d->transaction_id)->first(),
            'events' => $events->forSubjects([$d->id]),
        ]);
    }

    public function respond(Request $request, string $dispute, DisputeService $disputes): RedirectResponse
    {
        $v = $request->validate(['response' => ['required', 'string', 'max:10000'], 'evidence' => ['nullable', 'array', 'max:20'], 'evidence.*' => ['nullable', 'string', 'max:500']]);
        $d = Dispute::query()->where('environment', $this->env())->whereKey($dispute)->firstOrFail();
        try {
            $disputes->respond($d->id, $v['response'], DisputeController::evidence($v['evidence'] ?? []), $this->tenants->current()->actor);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Thank you. Your response was submitted for review.');
    }

    public function accept(string $dispute, DisputeService $disputes): RedirectResponse
    {
        $d = Dispute::query()->where('environment', $this->env())->whereKey($dispute)->firstOrFail();
        try {
            $disputes->accept($d->id, $this->tenants->current()->actor);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'You accepted this chargeback. The case is closed.');
    }

    private function env(): string
    {
        return (string) session('environment', 'sandbox');
    }
}
