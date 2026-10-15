<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use AmpliRail\Money\Money;
use App\Support\Amount;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Events\Contracts\EventLog;
use Modules\Identity\Contracts\StepUpVerifier;
use Modules\Ledger\Contracts\Ledger;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Infrastructure\Refund;
use Modules\Payments\Infrastructure\Transaction;
use Modules\Payments\Infrastructure\TransactionAttempt;
use Modules\Webhooks\Application\WebhookService;
use Modules\Webhooks\Infrastructure\WebhookDelivery;

/** Transactions screen: detail with attempts, ledger, event timeline and webhook deliveries; refund, void, replay. */
final class TransactionController extends AdminController
{
    public function index(Request $request): View
    {
        $q = Transaction::query()->orderByDesc('created_at');
        foreach (['status', 'environment', 'merchant_id', 'connector'] as $f) {
            if (is_string($v = $request->query($f)) && $v !== '') {
                $q->where($f, $v);
            }
        }
        if (is_string($id = $request->query('q')) && $id !== '') {
            $q->where(fn ($w) => $w->where('id', $id)->orWhere('order_reference', $id)->orWhere('provider_txn_id', $id));
        }
        $rows = $q->paginate(50)->withQueryString();
        $names = Merchant::query()->whereIn('id', collect($rows->items())->pluck('merchant_id')->unique())->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]);

        return view('admin.transactions.index', ['rows' => $rows, 'names' => $names]);
    }

    public function show(string $transaction, EventLog $events, Ledger $ledger): View
    {
        $t = Transaction::query()->whereKey($transaction)->firstOrFail();
        $refunds = Refund::query()->where('transaction_id', $t->id)->orderBy('created_at')->get();

        return view('admin.transactions.show', [
            't' => $t,
            'merchant' => Merchant::query()->whereKey($t->merchant_id)->first(),
            'attempts' => TransactionAttempt::query()->where('transaction_id', $t->id)->orderBy('created_at')->orderBy('id')->get(),
            'refunds' => $refunds,
            'ledger' => array_merge($ledger->postingsForSource($t->id), ...$refunds->map(fn ($r) => $ledger->postingsForSource($r->id))->all()),
            'events' => $events->forSubjects([$t->id, ...$refunds->map(fn (Refund $r): string => $r->id)->all()]),
            'deliveries' => WebhookDelivery::query()->where('payload->data->transaction_id', $t->id)->orderBy('created_at')->get(),
        ]);
    }

    public function refund(Request $request, string $transaction, PaymentGateway $payments, StepUpVerifier $stepUp): RedirectResponse
    {
        $d = $request->validate(['amount' => ['required', 'string', 'max:20'], 'reason' => ['nullable', 'string', 'max:255'], 'step_up_code' => ['nullable', 'string', 'max:8']]);
        $t = Transaction::query()->whereKey($transaction)->firstOrFail();
        $minor = Amount::toMinor($d['amount']) ?? 0;
        if ($minor > (int) config('amplirail.payments.refund_step_up_threshold_minor') && ! $stepUp->verify($this->userId(), (string) ($d['step_up_code'] ?? ''))) {
            return back()->with('error', 'Refunds above the threshold need your authenticator code.');
        }
        $o = $payments->refund($t->id, Money::of($minor, $t->currency), Ids::new('admin-refund'), $d['reason'] ?? null);

        return back()->with(($o->resource['status'] ?? '') === 'succeeded' ? 'status' : 'error', 'Refund '.($o->resource['status'] ?? 'failed').'.');
    }

    public function void(string $transaction, PaymentGateway $payments): RedirectResponse
    {
        $payments->void($transaction, Ids::new('admin-void'));

        return back()->with('status', 'Transaction voided.');
    }

    public function replay(string $delivery, WebhookService $webhooks): RedirectResponse
    {
        $d = WebhookDelivery::query()->whereKey($delivery)->firstOrFail();
        $webhooks->replay($d, $this->actor());

        return back()->with('status', 'Webhook replay queued (audited).');
    }
}
