<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use AmpliRail\Money\Money;
use App\Http\Requests\MoneyRequest;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Infrastructure\Refund;
use Modules\Payments\Infrastructure\Transaction;

final class RefundApiController extends ApiController
{
    public function store(MoneyRequest $request, PaymentGateway $payments): JsonResponse
    {
        $v = $request->validated();
        $t = Transaction::query()->whereKey((string) $v['transaction_id'])->first();
        if ($t === null || $t->environment !== $this->environment($request)) {
            throw ApiException::notFound('transaction');
        }
        $o = $payments->refund($t->id, Money::of((int) $v['amount']['amount_minor'], (string) $v['amount']['currency']),
            $this->idempotencyKey($request), $v['reason'] ?? null);

        return response()->json($o->resource, $o->httpStatus)->header('Idempotent-Replayed', $o->replayed ? 'true' : 'false');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->page($request, Refund::query()->where('environment', $this->environment($request)), fn (Refund $r) => $r->toApi()));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $r = Refund::query()->whereKey($id)->where('environment', $this->environment($request))->first() ?? throw ApiException::notFound('refund');

        return response()->json($r->toApi());
    }
}
