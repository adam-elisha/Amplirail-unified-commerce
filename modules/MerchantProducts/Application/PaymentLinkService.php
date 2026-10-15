<?php

declare(strict_types=1);

namespace Modules\MerchantProducts\Application;

use AmpliRail\Links\PaymentLinks;
use AmpliRail\Money\Money;
use App\Support\Actor;
use App\Support\ApiException;
use App\Support\Idempotency;
use App\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Audit\Contracts\AuditTrail;
use Modules\MerchantProducts\Infrastructure\PaymentLink;
use Modules\MerchantProducts\Mail\PaymentLinkMail;
use Modules\Onboarding\Contracts\MerchantDirectory;
use Modules\Payments\Contracts\ChargeCommand;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Contracts\PaymentOutcome;
use Modules\Tenancy\Contracts\TenantContext;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * Signed payment and invoice links: pay.amplirail.com/l/<token>. Amount, currency, merchant and expiry are signed by the
 * tested core PaymentLinks, so nobody can change the price. One successful payment per link.
 */
final class PaymentLinkService
{
    public function __construct(
        private readonly PaymentLinks $signer,
        private readonly TenantScope $tenants,
        private readonly Idempotency $idempotency,
        private readonly MerchantDirectory $merchants,
        private readonly PaymentGateway $payments,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{email?:string, phone?:string}  $sendTo
     * @return array{0:int, 1:array<string,mixed>}
     */
    public function create(string $merchantId, string $environment, Money $amount, string $reference, ?string $description, int $expiresInHours, array $sendTo, string $idempotencyKey, Actor $actor): array
    {
        $ctx = $this->tenants->current();
        $profile = $this->merchants->profile($merchantId) ?? throw ApiException::notFound('merchant');
        if (! $profile->canProcess($environment)) {
            throw ApiException::forbidden('This merchant cannot accept payments in '.$environment.'.');
        }
        $scope = 'payment_links.create:'.$merchantId;
        $request = ['env' => $environment, 'amount' => $amount->minor, 'currency' => $amount->currency, 'reference' => $reference,
            'description' => $description, 'hours' => $expiresInHours, 'send_to' => $sendTo];
        $begin = $this->idempotency->begin($ctx->tenantId, $scope, $idempotencyKey, $request);
        if ($begin['status'] === Idempotency::REPLAY) {
            return [(int) ($begin['response_status'] ?? 200), $begin['response'] ?? []];
        }
        if ($begin['status'] !== Idempotency::NEW) {
            throw ApiException::conflict('idempotency_key_reused', 'This Idempotency-Key was used with a different request or is in progress.');
        }

        try {
            return DB::transaction(function () use ($merchantId, $environment, $amount, $reference, $description, $expiresInHours, $sendTo, $ctx, $scope, $idempotencyKey, $actor, $profile): array {
                $expires = now()->addHours(max(1, min($expiresInHours, 24 * 90)));
                $token = $this->signer->create($merchantId, $amount->minor, $amount->currency, $reference, $expires->getTimestamp());
                $link = PaymentLink::query()->create([
                    'id' => Ids::new('pl'), 'merchant_id' => $merchantId, 'environment' => $environment, 'amount_minor' => $amount->minor,
                    'currency' => $amount->currency, 'reference' => $reference, 'description' => $description, 'token' => $token,
                    'token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'status' => 'open', 'send_to' => $sendTo === [] ? null : $sendTo,
                ]);
                $this->audit->record('payment_link.created', 'payment_link', $link->id, null, ['amount_minor' => $amount->minor, 'currency' => $amount->currency], $actor);
                if (isset($sendTo['email'])) {
                    Mail::to($sendTo['email'])->queue(new PaymentLinkMail($profile->displayName, $link->url(), Money::of($amount->minor, $amount->currency)->format(), $reference));
                }
                $body = $link->toApi();
                $this->idempotency->complete($ctx->tenantId, $scope, $idempotencyKey, 201, $body);

                return [201, $body];
            });
        } catch (\Throwable $e) {
            $this->idempotency->release($ctx->tenantId, $scope, $idempotencyKey);
            throw $e;
        }
    }

    /** Resolves a public token to its link and sets the tenant context for the pay page. Null when invalid or expired. */
    public function open(string $token): ?PaymentLink
    {
        $claims = $this->signer->verify($token, time());
        if ($claims === null) {
            return null;
        }
        $tenantId = $this->merchants->tenantOf((string) $claims['m']);
        if ($tenantId === null) {
            return null;
        }
        $this->tenants->set(new TenantContext($tenantId, '/'.$tenantId.'/', (string) $claims['m'], new Actor('customer', 'pay_page')));
        $link = PaymentLink::query()->where('token_hash', hash('sha256', $token))->first();
        if ($link === null || $link->amount_minor !== (int) $claims['a'] || $link->currency !== $claims['c']) {
            return null;
        }

        return $link;
    }

    /**
     * @param  array{brand?:string,last4?:string,type?:string}|null  $card
     * @param  array<string,string>  $metadata
     */
    public function pay(PaymentLink $link, string $paymentToken, ?array $card, array $metadata = []): PaymentOutcome
    {
        if ($link->status !== 'open' || $link->expires_at->isPast()) {
            throw ApiException::invalid('link_not_payable', 'This payment link is no longer payable.');
        }
        $outcome = $this->payments->charge(new ChargeCommand(
            $link->merchant_id, $link->environment, 'link:'.$link->id.':'.substr(hash('sha256', $paymentToken), 0, 16),
            Money::of($link->amount_minor, $link->currency), $paymentToken, $link->reference, channel: 'payment_link',
            card: $card, metadata: $metadata, paymentLinkId: $link->id,
        ));
        if ($outcome->status() === 'approved') {
            DB::transaction(function () use ($link, $outcome): void {
                $updated = PaymentLink::query()->whereKey($link->id)->where('status', 'open')
                    ->update(['status' => 'paid', 'transaction_id' => $outcome->id(), 'updated_at' => now()]);
                if ($updated === 1) {
                    $this->audit->record('payment_link.paid', 'payment_link', $link->id, null, ['transaction_id' => $outcome->id()]);
                }
            });
        }

        return $outcome;
    }

    public function cancel(PaymentLink $link, Actor $actor): void
    {
        if ($link->status === 'open') {
            $link->forceFill(['status' => 'cancelled'])->save();
            $this->audit->record('payment_link.cancelled', 'payment_link', $link->id, null, null, $actor);
        }
    }
}
