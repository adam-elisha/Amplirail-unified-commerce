<?php

declare(strict_types=1);

namespace Modules\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent mixin for tenant-scoped tables. Adds tenant_id to every query and insert, and refuses to run without
 * a TenantContext. PostgreSQL row-level security enforces the same rule a second time in the database.
 *
 * Models that also hold merchant data restrict merchant callers to their own merchant via merchantScopeColumn().
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $ctx = app(TenantScope::class)->current();
            $model = $query->getModel();
            $query->where($model->qualifyColumn('tenant_id'), $ctx->tenantId);

            $column = method_exists($model, 'merchantScopeColumn') ? $model->merchantScopeColumn() : null;
            if ($ctx->merchantId !== null && $column !== null) {
                $query->where($model->qualifyColumn($column), $ctx->merchantId);
            }
        });

        static::creating(function (Model $model): void {
            $ctx = app(TenantScope::class)->current();
            $current = $model->getAttribute('tenant_id');
            if ($current !== null && $current !== $ctx->tenantId) {
                throw new \LogicException('Cannot write a row for another tenant.');
            }
            $model->setAttribute('tenant_id', $ctx->tenantId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new \LogicException('tenant_id is immutable.');
            }
        });
    }
}
