<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use AmpliRail\Money\Money;
use App\Http\Requests\CreateTransactionRequest;
use App\Http\Requests\MoneyRequest;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Events\Contracts\EventLog;
use Modules\Ledger\Contracts\Ledger;
use Modules\Payments\Contracts\ChargeCommand;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Contracts\PaymentOutcome;
use Modules\Payments\Infrastructure\Transaction;

final class TransactionApiController extends ApiController
{
    public function __construct(private readonly PaymentGateway $payments) {}

    public function store(CreateTransactionRequest $request): JsonResponse
    {
        $v = $request->validated();
        $outcome = $this->payments->charge(new ChargeCommand(
            merchantId: $this->merchantId($request),
            environment: $this->environment($request),
            idempotencyKey: $this->idempotencyKey($request),
            amount: Money::of((int) $v['amount']['amount_minor'], (string) $v['amount']['currency']),
            paymentToken: (string) $v['payment_token'],
            orderReference: (string) $v['order_reference'],
            capture: (bool) ($v['capture'] ?? true),
            channel: 'api',
            initiator: (string) ($v['initiator'] ?? 'customer'),
            card: $v['card'] ?? null,
            metadata: isset($v['simulator']) && $this->environment($request) === 'sandbox' ? ['sim' => (string) $v['simulator']] : [],
        ));

        return $this->respond($outcome);
    }

    public function index(Request $request): JsonResponse
    {
        $q = Transaction::query()->where('environment', $this->environment($request));
        if (is_string($s = $request->query('status'))) {
            $q->where('status', $s);
        }
        if (is_string($from = $request->query('from')) && strtotime($from) !== false) {
            $q->where('created_at', '>=', $from);
        }
        if (is_string($to = $request->query('to')) && strtotime($to) !== false) {
            $q->where('created_at', '<', date('Y-m-d', (int) strtotime($to.' +1 day')));
        }

        return response()->json($this->page($request, $q, fn (Transaction $t) => $t->toApi()));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->find($request, $id)->toApi());
    }

    public function timeline(Request $request, string $id, EventLog $events, Ledger $ledger): JsonResponse
    {
        $t = $this->find($request, $id);

        return response()->json([
            'transaction' => $t->toApi(),
            'events' => $events->forSubjects([$t->id]),
            'ledger' => $ledger->postingsForSource($t->id),
        ]);
    }

    public function capture(MoneyRequest $request, string $id): JsonResponse
    {
        $this->find($request, $id);
        $v = $request->validated();
        $amount = isset($v['amount']) ? Money::of((int) $v['amount']['amount_minor'], (string) $v['amount']['currency']) : null;

        return $this->respond($this->payments->capture($id, $amount, $this->idempotencyKey($request)));
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id);

        return $this->respond($this->payments->void($id, $this->idempotencyKey($request)));
    }

    private function find(Request $request, string $id): Transaction
    {
        $t = Transaction::query()->whereKey($id)->first();
        if ($t === null || $t->environment !== $this->environment($request)) {
            throw ApiException::notFound('transaction');
        }

        return $t;
    }

    private function respond(PaymentOutcome $o): JsonResponse
    {
        return response()->json($o->resource, $o->httpStatus)->header('Idempotent-Replayed', $o->replayed ? 'true' : 'false');
    }
}
