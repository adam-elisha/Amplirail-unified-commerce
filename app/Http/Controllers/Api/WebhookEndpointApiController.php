<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Webhooks\Application\WebhookService;
use Modules\Webhooks\Infrastructure\WebhookEndpoint;

/** The signing secret (arwhk_...) is returned once, at creation or rotation. */
final class WebhookEndpointApiController extends ApiController
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', WebhookService::SUBSCRIBABLE)],
        ]);
        $created = $this->webhooks->create($this->merchantId($request), $this->environment($request), (string) $v['url'], array_values($v['events']), $this->actor());

        return response()->json($created['endpoint']->toApi() + ['secret' => $created['secret']], 201);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->page($request, WebhookEndpoint::query()->where('environment', $this->environment($request)), fn (WebhookEndpoint $e) => $e->toApi()));
    }

    public function rotate(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->find($request, $id);

        return response()->json($endpoint->toApi() + ['secret' => $this->webhooks->rotateSecret($endpoint, $this->actor())]);
    }

    public function test(Request $request, string $id): JsonResponse
    {
        $delivery = $this->webhooks->sendTest($this->find($request, $id));

        return response()->json(['object' => 'webhook_delivery', 'id' => $delivery->id, 'status' => $delivery->status], 202);
    }

    private function find(Request $request, string $id): WebhookEndpoint
    {
        return WebhookEndpoint::query()->whereKey($id)->where('environment', $this->environment($request))->first()
            ?? throw ApiException::notFound('webhook endpoint');
    }
}
