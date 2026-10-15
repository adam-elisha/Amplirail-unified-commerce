<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Audit\Contracts\AuditTrail;
use Throwable;

/**
 * Feature flags: config defaults with audited runtime overrides in feature_flags.
 * Fail closed: an unknown flag, or a flag store that cannot be read, is OFF.
 */
final class FeatureFlags
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function enabled(string $key): bool
    {
        $default = config("amplirail.features.$key");
        if ($default === null) {
            return false;
        }
        try {
            $row = DB::table('feature_flags')->where('key', $key)->first();
        } catch (Throwable $e) {
            Log::error('feature_flag.store_unavailable', ['flag' => $key, 'error' => get_class($e)]);

            return false;
        }

        return $row !== null ? (bool) $row->enabled : (bool) $default;
    }

    /** @return array<string,bool> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys((array) config('amplirail.features')) as $key) {
            $out[(string) $key] = $this->enabled((string) $key);
        }

        return $out;
    }

    public function set(string $key, bool $enabled, Actor $actor, string $reason): void
    {
        if (config("amplirail.features.$key") === null) {
            throw new \InvalidArgumentException("Unknown feature flag $key");
        }
        $before = $this->enabled($key);
        DB::transaction(function () use ($key, $enabled, $actor, $reason, $before): void {
            DB::table('feature_flags')->upsert(
                [['key' => $key, 'enabled' => $enabled, 'updated_by' => $actor->id, 'reason' => $reason, 'updated_at' => now()]],
                ['key'],
                ['enabled', 'updated_by', 'reason', 'updated_at'],
            );
            $this->audit->record('feature_flag.changed', 'feature_flag', $key, ['enabled' => $before], ['enabled' => $enabled, 'reason' => $reason], $actor);
        });
    }
}
