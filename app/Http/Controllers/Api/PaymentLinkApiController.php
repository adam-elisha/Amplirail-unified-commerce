<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use AmpliRail\Money\Money;
use App\Http\Requests\CreatePaymentLinkRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\MerchantProducts\Application\PaymentLinkService;
use Modules\MerchantProducts\Infrastructure\PaymentLink;

final class PaymentLinkApiController extends ApiController
{
    public function store(CreatePaymentLinkRequest $request, PaymentLinkService $links): JsonResponse
    {
        $v = $request->validated();
        [$status, $body] = $links->create($this->merchantId($request), $this->environment($request),
            Money::of((int) $v['amount']['amount_minor'], (string) $v['amount']['currency']), (string) $v['reference'],
            $v['description'] ?? null, (int) ($v['expires_in_hours'] ?? 72), (array) ($v['send_to'] ?? []),
            $this->idempotencyKey($request), $this->actor());

        return response()->json($body, $status);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->page($request, PaymentLink::query()->where('environment', $this->environment($request)), fn (PaymentLink $l) => $l->toApi()));
    }
}
