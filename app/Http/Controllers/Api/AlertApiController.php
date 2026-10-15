<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\AlertResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\ApiPrincipal;

/**
 * POST /v1/alerts — for alert networks and their resellers (OAuth2 client credentials with scope alerts.write).
 * Answers in the same call: refunded, already_refunded, chargeback_open, declined, not_found, refund_failed or rdr_resolved.
 */
final class AlertApiController
{
    public function store(Request $request, AlertResponder $alerts): JsonResponse
    {
        $v = $request->validate([
            'provider' => ['required', 'in:'.implode(',', array_keys(AlertResponder::PROVIDERS))],
            'alert_id' => ['required', 'string', 'max:100'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'transaction_id' => ['nullable', 'string', 'max:40'],
            'provider_reference' => ['nullable', 'string', 'max:100'],
            'card_last4' => ['nullable', 'digits:4'],
            'transaction_date' => ['nullable', 'date'],
            'alert_type' => ['nullable', 'in:fraud,dispute'],
        ]);
        /** @var ApiPrincipal $principal */
        $principal = $request->attributes->get(ApiPrincipal::class);
        try {
            $a = $alerts->handle($v['provider'], $v['alert_id'], $v, 'api', $principal->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_alert', 'message' => $e->getMessage()]], 422);
        }

        return response()->json([
            'object' => 'dispute_alert',
            'id' => $a->id,
            'provider' => $a->provider,
            'alert_id' => $a->provider_alert_id,
            'outcome' => $a->status,
            'transaction_id' => $a->transaction_id,
            'refund_id' => $a->refund_id,
            'note' => $a->outcome_note,
        ], $a->wasRecentlyCreated ? 201 : 200);
    }
}
