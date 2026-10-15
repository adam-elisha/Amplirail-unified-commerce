<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\ApiPrincipal;
use Modules\Tenancy\Contracts\TenantScope;

abstract class ApiController
{
    protected function principal(Request $request): ApiPrincipal
    {
        $p = $request->attributes->get(ApiPrincipal::class);
        if (! $p instanceof ApiPrincipal) {
            throw new \LogicException('No API principal on request.');
        }

        return $p;
    }

    protected function merchantId(Request $request): string
    {
        return (string) $this->principal($request)->merchantId;
    }

    protected function environment(Request $request): string
    {
        return (string) ($this->principal($request)->environment ?? 'sandbox');
    }

    protected function actor(): Actor
    {
        return app(TenantScope::class)->current()->actor;
    }

    protected function idempotencyKey(Request $request): string
    {
        return (string) $request->headers->get('Idempotency-Key');
    }

    /**
     * Cursor pagination on prefixed ULID ids, which sort by creation time (master document 13).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string,mixed>  $map
     * @return array<string,mixed>
     */
    protected function page(Request $request, Builder $query, callable $map): array
    {
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        if (is_string($cursor = $request->query('cursor')) && $cursor !== '') {
            $query->where('id', '<', $cursor);
        }
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return [
            'object' => 'list',
            'data' => $rows->map($map)->values()->all(),
            'has_more' => $more,
            'next_cursor' => $more ? $rows->last()?->getKey() : null,
        ];
    }
}
