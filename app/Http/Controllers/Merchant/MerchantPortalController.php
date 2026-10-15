<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use AmpliRail\Money\Money;
use App\Support\Actor;
use App\Support\Amount;
use App\Support\Export\StatementDownloads;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Modules\Identity\Application\ApiKeyService;
use Modules\Identity\Application\EmailOtp;
use Modules\Identity\Contracts\StepUpVerifier;
use Modules\Identity\Infrastructure\ApiKey;
use Modules\Identity\Infrastructure\User;
use Modules\MerchantProducts\Application\PaymentLinkService;
use Modules\MerchantProducts\Infrastructure\PaymentLink;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Domain\MerchantStateMachine;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\MerchantDocument;
use Modules\Payments\Contracts\ChargeCommand;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Contracts\PaymentReports;
use Modules\Payments\Infrastructure\Refund;
use Modules\Payments\Infrastructure\Transaction;
use Modules\Settlement\Infrastructure\PayoutStatement;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Webhooks\Application\WebhookService;
use Modules\Webhooks\Infrastructure\WebhookDelivery;
use Modules\Webhooks\Infrastructure\WebhookEndpoint;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant portal. The tenant context carries the merchant ID, so every query below is automatically limited to this
 * merchant (and RLS-limited to the tenant). The sandbox/live switch only changes what is shown and used.
 */
final class MerchantPortalController
{
    public function __construct(private readonly TenantScope $tenants) {}

    public function dashboard(PaymentReports $reports, DocumentChecklist $checklist): View
    {
        $m = $this->merchant();

        return view('merchant.dashboard', [
            'm' => $m,
            'env' => $this->env(),
            'progress' => MerchantStateMachine::PROGRESS,
            'summary' => $reports->merchantSummary($m->id, 30, $this->env()),
            'checklist' => $checklist->summary($m),
            'recent' => Transaction::query()->where('environment', $this->env())->orderByDesc('created_at')->limit(8)->get(),
        ]);
    }

    public function environment(Request $request): RedirectResponse
    {
        $env = $request->validate(['environment' => ['required', 'in:sandbox,live']])['environment'];
        if ($env === 'live' && $this->merchant()->state !== 'active') {
            return back()->with('error', 'Live mode opens after your account is activated.');
        }
        $request->session()->put('environment', $env);

        return back()->with('status', 'Showing '.$env.' data.');
    }

    public function transactions(Request $request): View
    {
        $q = Transaction::query()->where('environment', $this->env())->orderByDesc('created_at');
        if (is_string($s = $request->query('status')) && $s !== '') {
            $q->where('status', $s);
        }

        return view('merchant.transactions.index', ['rows' => $q->paginate(50)->withQueryString()]);
    }

    public function transaction(string $transaction): View
    {
        $t = Transaction::query()->whereKey($transaction)->firstOrFail();

        return view('merchant.transactions.show', [
            't' => $t,
            'refunds' => Refund::query()->where('transaction_id', $t->id)->orderBy('created_at')->get(),
            'threshold' => (int) config('amplirail.payments.refund_step_up_threshold_minor'),
        ]);
    }

    public function sendStepUp(EmailOtp $otp): RedirectResponse
    {
        /** @var User $user */
        $user = User::query()->findOrFail($this->actor()->id);
        $result = $otp->issue($user, 'step_up');

        return back()->with($result === EmailOtp::SENT ? 'status' : 'error', $result === EmailOtp::SENT ? 'We emailed you a verification code.' : 'Please wait before requesting another code.');
    }

    public function refund(Request $request, string $transaction, PaymentGateway $payments, StepUpVerifier $stepUp): RedirectResponse
    {
        $d = $request->validate(['amount' => ['required', 'string', 'max:20'], 'reason' => ['nullable', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:8'], 'form_key' => ['required', 'string', 'max:60']]);
        $t = Transaction::query()->whereKey($transaction)->firstOrFail();
        $minor = Amount::toMinor($d['amount']) ?? 0;
        if ($minor > (int) config('amplirail.payments.refund_step_up_threshold_minor') && ! $stepUp->verify($this->actor()->id, (string) ($d['code'] ?? ''))) {
            return back()->with('error', 'Refunds above the threshold need the code we email you.');
        }
        $o = $payments->refund($t->id, Money::of($minor, $t->currency), (string) $d['form_key'], $d['reason'] ?? null);

        return back()->with(($o->resource['status'] ?? '') === 'succeeded' ? 'status' : 'error', 'Refund '.($o->resource['status'] ?? 'failed').'.');
    }

    public function void(Request $request, string $transaction, PaymentGateway $payments): RedirectResponse
    {
        $key = (string) $request->validate(['form_key' => ['required', 'string', 'max:60']])['form_key'];
        $payments->void(Transaction::query()->whereKey($transaction)->firstOrFail()->id, $key);

        return back()->with('status', 'Transaction voided.');
    }

    public function terminal(): View
    {
        return view('merchant.terminal', ['env' => $this->env(), 'm' => $this->merchant(), 'formKey' => Ids::new('vt')]);
    }

    public function charge(Request $request, PaymentGateway $payments): RedirectResponse
    {
        $d = $request->validate(['amount' => ['required', 'string', 'max:20'], 'reference' => ['required', 'string', 'max:120'],
            'payment_token' => ['required', 'string', 'max:255'], 'capture' => ['sometimes', 'boolean'], 'form_key' => ['required', 'string', 'max:60']]);
        $minor = Amount::toMinor($d['amount']);
        if ($minor === null || $minor < 1) {
            return back()->withInput()->with('error', 'Enter a valid amount, for example 25.00.');
        }
        $m = $this->merchant();
        $o = $payments->charge(new ChargeCommand($m->id, $this->env(), (string) $d['form_key'], Money::of($minor, $m->currency), (string) $d['payment_token'],
            (string) $d['reference'], capture: (bool) ($d['capture'] ?? true), channel: 'virtual_terminal', initiator: 'merchant'));

        return redirect()->route('merchant.transactions.show', $o->id())->with($o->status() === 'approved' ? 'status' : 'error', 'Payment '.$o->status().'.');
    }

    public function links(): View
    {
        return view('merchant.links', ['links' => PaymentLink::query()->where('environment', $this->env())->orderByDesc('created_at')->limit(100)->get(),
            'env' => $this->env(), 'm' => $this->merchant(), 'formKey' => Ids::new('pl')]);
    }

    public function createLink(Request $request, PaymentLinkService $links): RedirectResponse
    {
        $d = $request->validate(['amount' => ['required', 'string', 'max:20'], 'reference' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'], 'expires_in_hours' => ['required', 'integer', 'min:1', 'max:2160'], 'form_key' => ['required', 'string', 'max:60']]);
        $minor = Amount::toMinor($d['amount']);
        if ($minor === null || $minor < 1) {
            return back()->withInput()->with('error', 'Enter a valid amount.');
        }
        $m = $this->merchant();
        $links->create($m->id, $this->env(), Money::of($minor, $m->currency), (string) $d['reference'], $d['description'] ?? null, (int) $d['expires_in_hours'],
            isset($d['email']) ? ['email' => (string) $d['email']] : [], (string) $d['form_key'], $this->actor());

        return back()->with('status', 'Payment request created'.(isset($d['email']) ? ' and emailed.' : '.'));
    }

    public function statements(): View
    {
        return view('merchant.statements', ['rows' => PayoutStatement::query()->where('status', 'issued')->orderByDesc('period_start')->paginate(25)]);
    }

    public function downloadStatement(string $statement, string $format, StatementDownloads $downloads): Response
    {
        abort_unless(in_array($format, ['csv', 'pdf', 'xlsx'], true), 404);

        return $downloads->download(PayoutStatement::query()->whereKey($statement)->where('status', 'issued')->firstOrFail(), $format);
    }

    public function documents(DocumentChecklist $checklist): View
    {
        $m = $this->merchant();

        return view('merchant.documents', ['items' => $checklist->items($m), 'summary' => $checklist->summary($m),
            'documents' => MerchantDocument::query()->where('status', 'active')->orderByDesc('created_at')->get(), 'checklist' => $checklist]);
    }

    public function uploadDocument(Request $request, DocumentChecklist $checklist): RedirectResponse
    {
        $request->validate(['document_type' => ['required', 'regex:/^[a-z0-9_]{2,60}$/'], 'file' => ['required', 'file']]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $checklist->upload($this->merchant(), $file, (string) $request->input('document_type'), $this->actor());

        return back()->with('status', 'Thank you. Your document was uploaded securely.');
    }

    public function developers(?string $revealed = null, ?string $label = null): View
    {
        return view('merchant.developers', [
            'keys' => ApiKey::query()->where('merchant_id', $this->merchant()->id)->whereNull('revoked_at')->orderBy('environment')->orderBy('type')->get(),
            'endpoints' => WebhookEndpoint::query()->orderByDesc('created_at')->get(),
            'deliveries' => WebhookDelivery::query()->orderByDesc('created_at')->limit(25)->get(),
            'events' => WebhookService::SUBSCRIBABLE,
            'env' => $this->env(),
            'revealed' => $revealed,
            'revealedLabel' => $label,
        ]);
    }

    public function revealKey(string $key, ApiKeyService $keys): View|RedirectResponse
    {
        $apiKey = ApiKey::query()->whereKey($key)->where('merchant_id', $this->merchant()->id)->firstOrFail();
        $secret = $keys->reveal($apiKey);
        if ($secret === null) {
            return back()->with('error', 'This key was already revealed. Roll it to get a new secret.');
        }

        return $this->developers($secret, 'Secret key '.$apiKey->environment);
    }

    public function rollKey(string $key, ApiKeyService $keys): View
    {
        $apiKey = ApiKey::query()->whereKey($key)->where('merchant_id', $this->merchant()->id)->whereNull('revoked_at')->firstOrFail();

        return $this->developers($keys->roll($apiKey, $this->actor()), 'New '.$apiKey->type.' key ('.$apiKey->environment.')');
    }

    public function createWebhook(Request $request, WebhookService $webhooks): View
    {
        $d = $request->validate(['url' => ['required', 'string', 'max:500'], 'events' => ['required', 'array', 'min:1'], 'events.*' => ['in:'.implode(',', WebhookService::SUBSCRIBABLE)]]);
        $created = $webhooks->create($this->merchant()->id, $this->env(), (string) $d['url'], array_values($d['events']), $this->actor());

        return $this->developers($created['secret'], 'Webhook signing secret');
    }

    public function testWebhook(string $endpoint, WebhookService $webhooks): RedirectResponse
    {
        $webhooks->sendTest(WebhookEndpoint::query()->whereKey($endpoint)->firstOrFail());

        return back()->with('status', 'Test event queued.');
    }

    private function merchant(): Merchant
    {
        return Merchant::query()->whereKey((string) $this->tenants->current()->merchantId)->firstOrFail();
    }

    private function env(): string
    {
        return $this->tenants->current()->environment === 'live' ? 'live' : 'sandbox';
    }

    private function actor(): Actor
    {
        return $this->tenants->current()->actor;
    }
}
