<?php

declare(strict_types=1);

namespace Modules\Payments\Application;

use AmpliRail\Connectors\Contracts\ConnectorResult;
use AmpliRail\Connectors\Contracts\PaymentRequest;
use AmpliRail\Money\Money;
use AmpliRail\Routing\Router;
use App\Support\ApiException;
use App\Support\Idempotency;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\SensitiveData;
use Connectors\ConnectorRegistry;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Modules\Events\Contracts\Outbox;
use Modules\Ledger\Contracts\Ledger;
use Modules\Onboarding\Contracts\MerchantDirectory;
use Modules\Onboarding\Contracts\MerchantProfile;
use Modules\Payments\Contracts\ChargeCommand;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Contracts\PaymentOutcome;
use Modules\Payments\Domain\DeclineCategory;
use Modules\Payments\Domain\TransactionStateMachine;
use Modules\Payments\Infrastructure\Refund;
use Modules\Payments\Infrastructure\Transaction;
use Modules\Payments\Infrastructure\TransactionAttempt;
use Modules\Routing\Contracts\MerchantAccounts;
use Modules\Tenancy\Contracts\TenantScope;
use Throwable;

/**
 * Payment orchestration with the tested core semantics (packages/amplirail-core TransactionService), persisted:
 *   idempotency -> screening -> routing (merchant's own active MIDs only) -> connector -> one DB transaction with the
 *   transaction, attempts, ledger journal, outbox event and idempotency completion.
 * Failover only on retryable provider errors. Declines are never rerouted (rule 11). Unknown outcomes are stored as
 * "unknown" for reconciliation and are never blindly retried. The connector call is outside the DB transaction.
 */
final class PaymentOrchestrator implements PaymentGateway
{
    public function __construct(
        private readonly TenantScope $tenants,
        private readonly Idempotency $idempotency,
        private readonly MerchantDirectory $merchants,
        private readonly MerchantAccounts $accounts,
        private readonly ConnectorRegistry $connectors,
        private readonly TransactionScreening $screening,
        private readonly Ledger $ledger,
        private readonly Outbox $outbox,
        private readonly RequestContext $request,
        private readonly Cache $cache,
    ) {}

    public function charge(ChargeCommand $c): PaymentOutcome
    {
        $ctx = $this->tenants->current();
        if (SensitiveData::containsPan($c->paymentToken) || SensitiveData::containsPan($c->orderReference)) {
            throw ApiException::invalid('card_number_rejected', 'Card numbers are never accepted. Use a token from AmpliRail hosted fields.');
        }
        $profile = $this->merchants->profile($c->merchantId) ?? throw ApiException::notFound('merchant');
        $this->assertCanProcess($profile, $c->environment);

        $scope = 'transactions.create:'.$c->merchantId;
        $begin = $this->idempotency->begin($ctx->tenantId, $scope, $c->idempotencyKey, $c->fingerprint());
        if ($replay = $this->handleNonNew($begin)) {
            return $replay;
        }

        try {
            $risk = $profile->riskProfile;
            $rules = [
                'max_ticket_minor' => (int) ($risk['max_ticket_minor'] ?? config('amplirail.payments.default_screening.max_ticket_minor')),
                'max_per_token_per_hour' => (int) ($risk['max_per_token_per_hour'] ?? config('amplirail.payments.default_screening.max_per_token_per_hour')),
                'blocked_tokens' => array_values(array_map('strval', (array) ($risk['blocked_tokens'] ?? []))),
            ];
            $screen = $this->screening->check($c->merchantId, $c->paymentToken, $c->amount->minor, $rules);
            if (! $screen['allow']) {
                return $this->persistCharge($c, $scope, 'blocked', 'blocked', null, null, $screen['reason'] ?? 'blocked', [], $profile);
            }

            $candidates = (new Router)->candidates($this->accounts->routable($c->merchantId, $c->environment), $c->merchantId, $c->amount->currency, $c->amount->minor);
            if ($candidates === []) {
                return $this->persistCharge($c, $scope, 'failed', 'failed', null, null, 'no_eligible_mid', [], $profile);
            }

            $attempts = [];
            foreach ($candidates as $mid) {
                $req = new PaymentRequest($c->idempotencyKey.':'.$mid->id, $mid->id, $c->amount, $c->paymentToken, $c->orderReference,
                    initiator: $c->initiator, metadata: $c->metadata);
                [$res, $latency] = $this->call($mid->connector, $c->capture ? 'sale' : 'authorize', $req);
                $attempts[] = ['mid' => $mid->id, 'connector' => $mid->connector, 'operation' => $c->capture ? 'sale' : 'authorize', 'result' => $res, 'latency' => $latency];
                if ($res->outcome === ConnectorResult::ERROR && $res->retryableError) {
                    continue; // safe failover: no provider record was created
                }
                [$status, $state] = match ($res->outcome) {
                    ConnectorResult::APPROVED => ['approved', $c->capture ? 'captured' : 'authorized'],
                    ConnectorResult::DECLINED => ['declined', 'declined'],
                    default => ['unknown', 'unknown'],
                };

                return $this->persistCharge($c, $scope, $status, $state, $mid->id, $res, $res->message, $attempts, $profile);
            }

            return $this->persistCharge($c, $scope, 'failed', 'failed', null, null, 'all_routes_unavailable', $attempts, $profile);
        } catch (Throwable $e) {
            $this->idempotency->release($ctx->tenantId, $scope, $c->idempotencyKey);
            throw $e;
        }
    }

    public function capture(string $transactionId, ?Money $amount, string $idempotencyKey): PaymentOutcome
    {
        return $this->followUp($transactionId, $idempotencyKey, 'capture', ['amount' => $amount?->minor], function (Transaction $t) use ($amount): array {
            TransactionStateMachine::assert($t->state, 'captured');
            $capture = $amount ?? Money::of($t->amount_minor, $t->currency);
            if ($capture->currency !== $t->currency || $capture->minor < 1 || $capture->minor > $t->amount_minor) {
                throw ApiException::invalid('invalid_capture_amount', 'Capture amount must be positive and not exceed the authorized amount.');
            }
            $res = $this->providerCall($t, 'capture', $capture);
            if (! $res->approved()) {
                return [409, ['error' => ['type' => 'api_error', 'code' => 'capture_failed', 'message' => $res->message]]];
            }

            return DB::transaction(function () use ($t, $capture): array {
                $t->forceFill(['state' => 'captured', 'captured_minor' => $capture->minor])->save();
                $this->postSale($t, $capture);
                $this->outbox->record('transaction.captured', 'transaction', $t->id, $this->eventData($t), environment: $t->environment);

                return [200, $t->toApi()];
            });
        });
    }

    public function void(string $transactionId, string $idempotencyKey): PaymentOutcome
    {
        return $this->followUp($transactionId, $idempotencyKey, 'void', [], function (Transaction $t): array {
            if ($t->refunded_minor > 0) {
                throw ApiException::invalid('void_not_allowed', 'A refunded transaction cannot be voided; refund instead.');
            }
            TransactionStateMachine::assert($t->state, 'voided');
            $res = $this->providerCall($t, 'void', Money::of($t->amount_minor, $t->currency));
            if (! $res->approved()) {
                return [409, ['error' => ['type' => 'api_error', 'code' => 'void_failed', 'message' => $res->message]]];
            }

            return DB::transaction(function () use ($t): array {
                $wasCaptured = $t->state === 'captured';
                $t->forceFill(['state' => 'voided'])->save();
                if ($wasCaptured && $t->merchant_account_id !== null) {
                    $amount = Money::of($t->captured_minor, $t->currency);
                    $this->ledger->post('void', $t->id, $t->environment, [
                        ['merchant_payable:'.$t->merchant_id, $amount],
                        ['processor_receivable:'.$t->merchant_account_id, $amount->negate()],
                    ]);
                }
                $this->outbox->record('transaction.voided', 'transaction', $t->id, $this->eventData($t), environment: $t->environment);

                return [200, $t->toApi()];
            });
        });
    }

    public function refund(string $transactionId, Money $amount, string $idempotencyKey, ?string $reason = null): PaymentOutcome
    {
        return $this->followUp($transactionId, $idempotencyKey, 'refund', ['amount' => $amount->minor, 'currency' => $amount->currency], function (Transaction $t) use ($amount, $reason, $idempotencyKey): array {
            if (! in_array($t->state, ['captured', 'partially_refunded'], true)) {
                throw ApiException::invalid('not_refundable', 'Only captured transactions can be refunded.');
            }
            if ($amount->currency !== $t->currency) {
                throw ApiException::invalid('currency_mismatch', 'Refund currency must match the transaction.');
            }
            if ($amount->minor < 1 || $amount->minor > $t->captured_minor - $t->refunded_minor) {
                throw ApiException::invalid('over_refundable_amount', 'Refund exceeds the refundable amount.');
            }
            $res = $this->providerCall($t, 'refund', $amount);

            return DB::transaction(function () use ($t, $amount, $reason, $idempotencyKey, $res): array {
                $refund = Refund::query()->create([
                    'id' => Ids::new('rf'),
                    'merchant_id' => $t->merchant_id,
                    'transaction_id' => $t->id,
                    'environment' => $t->environment,
                    'amount_minor' => $amount->minor,
                    'currency' => $amount->currency,
                    'status' => $res->approved() ? 'succeeded' : 'failed',
                    'reason' => $reason,
                    'provider_ref' => $res->providerTransactionId,
                    'idempotency_key' => $idempotencyKey,
                ]);
                if (! $res->approved()) {
                    $this->outbox->record('refund.failed', 'refund', $refund->id, ['merchant_id' => $t->merchant_id, 'transaction_id' => $t->id,
                        'amount_minor' => $amount->minor, 'currency' => $amount->currency], environment: $t->environment);

                    return [402, $refund->toApi() + ['message' => $res->message]];
                }
                $refunded = $t->refunded_minor + $amount->minor;
                $to = $refunded === $t->captured_minor ? 'refunded' : 'partially_refunded';
                TransactionStateMachine::assert($t->state, $to);
                $t->forceFill(['refunded_minor' => $refunded, 'state' => $to])->save();
                $this->ledger->post('refund', $refund->id, $t->environment, [
                    ['merchant_payable:'.$t->merchant_id, $amount],
                    ['processor_receivable:'.$t->merchant_account_id, $amount->negate()],
                ]);
                $this->outbox->record('refund.succeeded', 'refund', $refund->id, ['merchant_id' => $t->merchant_id, 'transaction_id' => $t->id,
                    'amount_minor' => $amount->minor, 'currency' => $amount->currency, 'refunded_minor' => $refunded], environment: $t->environment);

                return [201, $refund->toApi() + ['transaction' => $t->toApi()]];
            });
        });
    }

    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Shared idempotent wrapper for follow-up operations on an existing transaction (one per transaction at a time).
     *
     * @param  array<string,mixed>  $fingerprint
     * @param  callable(Transaction): array{0:int,1:array<string,mixed>}  $work
     */
    private function followUp(string $transactionId, string $key, string $operation, array $fingerprint, callable $work): PaymentOutcome
    {
        $ctx = $this->tenants->current();
        $t = Transaction::query()->whereKey($transactionId)->first() ?? throw ApiException::notFound('transaction');
        $this->assertCanProcess($this->merchants->profile($t->merchant_id) ?? throw ApiException::notFound('merchant'), $t->environment, followUp: true);
        $scope = "transactions.$operation:".$transactionId;
        $begin = $this->idempotency->begin($ctx->tenantId, $scope, $key, $fingerprint + ['txn' => $transactionId]);
        if ($replay = $this->handleNonNew($begin)) {
            return $replay;
        }
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new \LogicException('The cache store must support atomic locks (redis, database, file or array).');
        }
        $lock = $store->lock('txn-lock:'.$transactionId, 30);
        if (! $lock->get()) {
            $this->idempotency->release($ctx->tenantId, $scope, $key);
            throw ApiException::conflict('request_in_progress', 'Another operation on this transaction is in progress.');
        }
        try {
            $fresh = Transaction::query()->whereKey($transactionId)->firstOrFail();
            [$status, $body] = $work($fresh);
            $this->idempotency->complete($ctx->tenantId, $scope, $key, $status, $body);

            return new PaymentOutcome($status, $body);
        } catch (Throwable $e) {
            $this->idempotency->release($ctx->tenantId, $scope, $key);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** @param array{status:string, response_status?:int, response?:array<string,mixed>} $begin */
    private function handleNonNew(array $begin): ?PaymentOutcome
    {
        return match ($begin['status']) {
            Idempotency::NEW => null,
            Idempotency::REPLAY => new PaymentOutcome((int) ($begin['response_status'] ?? 200), $begin['response'] ?? [], true),
            Idempotency::CONFLICT => throw ApiException::conflict('idempotency_key_reused', 'This Idempotency-Key was used with a different request.'),
            default => throw ApiException::conflict('request_in_progress', 'A request with this Idempotency-Key is still in progress.'),
        };
    }

    private function assertCanProcess(MerchantProfile $profile, string $environment, bool $followUp = false): void
    {
        if (! in_array($environment, ['sandbox', 'live'], true)) {
            throw ApiException::invalid('invalid_environment', 'Unknown environment.');
        }
        // Follow-ups (refund, void, capture) stay possible for suspended merchants so customers can be made whole.
        if ($followUp && in_array($profile->state, ['suspended', 'active', 'closed'], true)) {
            return;
        }
        if (! $profile->canProcess($environment)) {
            throw new ApiException(403, 'invalid_request_error', $environment === 'live' ? 'merchant_not_live' : 'merchant_not_processing',
                $environment === 'live' ? 'This merchant is not activated for live processing.' : 'This merchant cannot process payments.');
        }
    }

    /** @return array{0: ConnectorResult, 1: int} */
    private function call(string $connector, string $op, PaymentRequest $req): array
    {
        $start = hrtime(true);
        try {
            $res = $this->connectors->get($connector)->{$op}($req);
        } catch (Throwable) {
            $res = new ConnectorResult(ConnectorResult::ERROR, null, null, 'provider_unavailable', 'Connector unavailable', retryableError: true);
        }

        return [$res, (int) ((hrtime(true) - $start) / 1_000_000)];
    }

    private function providerCall(Transaction $t, string $op, Money $amount): ConnectorResult
    {
        if ($t->merchant_account_id === null || $t->connector === null) {
            throw ApiException::invalid('no_route', 'Transaction has no processing route.');
        }
        $req = new PaymentRequest(Ids::new('op'), $t->merchant_account_id, $amount, 'tok_followup', $t->order_reference, $t->provider_txn_id);
        [$res, $latency] = $this->call($t->connector, $op, $req);
        TransactionAttempt::query()->create([
            'id' => Ids::new('tat'), 'transaction_id' => $t->id, 'merchant_account_id' => $t->merchant_account_id, 'connector' => $t->connector,
            'operation' => $op, 'outcome' => $res->outcome, 'response_code' => $res->responseCode, 'message' => mb_substr($res->message, 0, 255),
            'retryable' => $res->retryableError, 'soft_decline' => $res->softDecline, 'latency_ms' => $latency, 'created_at' => now(),
        ]);

        return $res;
    }

    /** @param list<array{mid:string,connector:string,operation:string,result:ConnectorResult,latency:int}> $attempts */
    private function persistCharge(ChargeCommand $c, string $scope, string $status, string $state, ?string $midId, ?ConnectorResult $res, ?string $reason, array $attempts, MerchantProfile $profile): PaymentOutcome
    {
        $ctx = $this->tenants->current();

        return DB::transaction(function () use ($c, $scope, $status, $state, $midId, $res, $reason, $attempts, $ctx, $profile): PaymentOutcome {
            $now = now();
            $t = Transaction::query()->create([
                'id' => Ids::new('txn'),
                'merchant_id' => $c->merchantId,
                'merchant_account_id' => $midId,
                'environment' => $c->environment,
                'idempotency_key' => $c->idempotencyKey,
                'kind' => $c->capture ? 'sale' : 'authorize',
                'status' => $status,
                'state' => $state,
                'amount_minor' => $c->amount->minor,
                'currency' => $c->amount->currency,
                'captured_minor' => $state === 'captured' ? $c->amount->minor : 0,
                'refunded_minor' => 0,
                'connector' => $midId !== null ? ($attempts[array_key_last($attempts)]['connector'] ?? null) : null,
                'provider_txn_id' => $res?->providerTransactionId,
                'auth_code' => $res?->authCode,
                'response_code' => $res?->responseCode,
                'decline_category' => $res !== null ? DeclineCategory::fromCode($res->responseCode, $res->outcome) : null,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'soft_decline' => $res !== null && $res->softDecline,
                'card_brand' => $c->card['brand'] ?? null,
                'card_last4' => $c->card['last4'] ?? null,
                'card_type' => $c->card['type'] ?? null,
                'entry_mode' => 'ecommerce',
                'token_fingerprint' => hash('sha256', $c->paymentToken),
                'order_reference' => $c->orderReference,
                'initiator' => $c->initiator,
                'channel' => $c->channel,
                'payment_link_id' => $c->paymentLinkId,
                'correlation_id' => $this->request->correlationId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($attempts as $a) {
                TransactionAttempt::query()->create([
                    'id' => Ids::new('tat'), 'transaction_id' => $t->id, 'merchant_account_id' => $a['mid'], 'connector' => $a['connector'],
                    'operation' => $a['operation'], 'outcome' => $a['result']->outcome, 'response_code' => $a['result']->responseCode,
                    'message' => mb_substr($a['result']->message, 0, 255), 'retryable' => $a['result']->retryableError,
                    'soft_decline' => $a['result']->softDecline, 'latency_ms' => $a['latency'], 'created_at' => $now,
                ]);
            }
            if ($state === 'captured') {
                $this->postSale($t, $c->amount);
            }
            $this->outbox->record('transaction.'.$status, 'transaction', $t->id, $this->eventData($t) + ['attempts' => count($attempts)],
                environment: $c->environment, orgPath: $profile->orgPath);

            $body = $t->toApi() + ['attempts' => count($attempts)];
            $httpStatus = 201;
            $this->idempotency->complete($ctx->tenantId, $scope, $c->idempotencyKey, $httpStatus, $body);

            return new PaymentOutcome($httpStatus, $body);
        });
    }

    private function postSale(Transaction $t, Money $amount): void
    {
        $this->ledger->post('sale', $t->id, $t->environment, [
            ['processor_receivable:'.$t->merchant_account_id, $amount],
            ['merchant_payable:'.$t->merchant_id, $amount->negate()],
        ]);
    }

    /** @return array<string,mixed> */
    private function eventData(Transaction $t): array
    {
        return [
            'merchant_id' => $t->merchant_id, 'transaction_id' => $t->id, 'status' => $t->status, 'state' => $t->state,
            'amount_minor' => $t->amount_minor, 'currency' => $t->currency, 'connector' => $t->connector,
            'merchant_account_id' => $t->merchant_account_id, 'decline_category' => $t->decline_category,
        ];
    }
}
