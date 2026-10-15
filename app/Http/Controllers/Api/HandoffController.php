<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\ApiException;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Onboarding\Application\HandoffPayload;
use Modules\Onboarding\Application\HandoffService;

/** AR-168: POST /v1/merchants/handoff (CRM service token with crm.handoff scope; Idempotency-Key required). */
final class HandoffController extends ApiController
{
    public function store(Request $request, HandoffService $handoff, FeatureFlags $flags): JsonResponse
    {
        if (! $flags->enabled('crm_handoff')) {
            throw ApiException::featureDisabled('crm_handoff');
        }
        $data = HandoffPayload::validate((array) $request->json()->all(), requireDealId: true);
        [$status, $body] = $handoff->handle($data, $this->idempotencyKey($request), 'crm');

        return response()->json($body, $status);
    }
}
