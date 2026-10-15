<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Onboarding\Application\CommandCenter;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Routing\Contracts\Eligibility;

final class MerchantApiController extends ApiController
{
    public function __construct(private readonly DocumentChecklist $checklist) {}

    // ----- service callers (merchants.read) -----

    public function show(string $merchant): JsonResponse
    {
        return response()->json($this->resource($this->load($merchant)));
    }

    public function eligibility(string $merchant, Eligibility $eligibility): JsonResponse
    {
        $m = $this->load($merchant);

        return response()->json(['merchant_id' => $m->id, 'result' => $eligibility->latest($m->id)]);
    }

    public function documents(string $merchant): JsonResponse
    {
        $m = $this->load($merchant);

        return response()->json(['merchant_id' => $m->id, 'summary' => $this->checklist->summary($m), 'items' => $this->checklist->items($m)]);
    }

    public function commandCenter(Request $request, CommandCenter $center): JsonResponse
    {
        return response()->json($request->boolean('sample') ? CommandCenter::sample() : $center->snapshot());
    }

    // ----- merchant secret key -----

    public function self(Request $request): JsonResponse
    {
        $m = $this->load($this->merchantId($request));

        return response()->json($this->resource($m) + ['checklist' => $this->checklist->items($m)]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['document_type' => ['required', 'string', 'max:60'], 'file' => ['required', 'file']]);
        $m = $this->load($this->merchantId($request));
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw ApiException::invalid('invalid_file', 'Upload exactly one file.');
        }
        $doc = $this->checklist->upload($m, $file, (string) $request->input('document_type'), $this->actor());

        return response()->json(['object' => 'document', 'id' => $doc->id, 'document_type' => $doc->document_type, 'status' => $doc->status], 201);
    }

    private function load(string $id): Merchant
    {
        return Merchant::query()->whereKey($id)->first() ?? throw ApiException::notFound('merchant');
    }

    /** @return array<string,mixed> */
    private function resource(Merchant $m): array
    {
        return [
            'id' => $m->id, 'object' => 'merchant', 'public_id' => $m->public_id, 'crm_deal_id' => $m->crm_deal_id,
            'legal_name' => $m->legal_name, 'dba' => $m->dba, 'state' => $m->state, 'state_changed_at' => $m->state_changed_at->toIso8601String(),
            'mcc' => $m->mcc, 'currency' => $m->currency, 'risk_tier' => $m->risk_tier, 'kyb_status' => $m->kyb_status,
            'processor_route' => $m->processor_route, 'sandbox_provisioned' => $m->sandbox_provisioned_at !== null,
            'live_at' => $m->live_at?->toIso8601String(), 'created_at' => $m->created_at->toIso8601String(),
        ];
    }
}
