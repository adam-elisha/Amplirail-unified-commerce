<?php

declare(strict_types=1);

namespace App\Support\Branding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Finds the brand for a host name. Unknown hosts get the platform (AmpliRail) brand. Cached for five minutes;
 * forget() is called whenever a brand is saved.
 */
final class BrandResolver
{
    private ?Brand $current = null;

    public function forHost(string $host): Brand
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return Brand::platform();
        }
        $tenantId = Cache::remember('brand:host:'.$host, 300, function () use ($host): string {
            if (! Schema::hasTable('brands')) {
                return '';
            }
            $r = BrandRecord::query()->where('status', 'active')->whereJsonContains('hosts', $host)->first();

            return $r->tenant_id ?? '';
        });

        return $tenantId === '' ? Brand::platform() : $this->forTenant($tenantId);
    }

    public function forTenant(string $tenantId): Brand
    {
        $r = BrandRecord::query()->where('tenant_id', $tenantId)->where('status', 'active')->first();

        return $r === null ? Brand::platform() : Brand::fromRecord($r);
    }

    public function setCurrent(Brand $brand): void
    {
        $this->current = $brand;
    }

    public function current(): Brand
    {
        return $this->current ?? Brand::platform();
    }

    /** @param list<string> $hosts */
    public static function forget(array $hosts): void
    {
        foreach ($hosts as $h) {
            Cache::forget('brand:host:'.self::normalizeHost($h));
        }
    }

    public static function normalizeHost(string $host): string
    {
        $host = mb_strtolower(trim($host));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) === 1 ? $host : '';
    }
}
