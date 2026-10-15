<?php

declare(strict_types=1);

namespace Modules\Tenancy\Contracts;

interface TenantScope
{
    /** @throws MissingTenantContext */
    public function current(): TenantContext;

    public function has(): bool;

    public function set(TenantContext $context): void;

    public function clear(): void;

    /**
     * Runs $fn inside $context and restores the previous context afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function run(TenantContext $context, callable $fn): mixed;
}
