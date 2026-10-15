<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Database-backed idempotency (AGENTS.md rule 6, core IdempotencyStore semantics): new | replay | conflict | in_flight.
 * A unique (tenant_id, scope, idem_key) index makes "new" race-free. complete() should run inside the same transaction
 * as the business write so the stored response can never disagree with the data. Keys are kept for 24 hours.
 */
final class Idempotency
{
    public const NEW = 'new';

    public const REPLAY = 'replay';

    public const CONFLICT = 'conflict';

    public const IN_FLIGHT = 'in_flight';

    /**
     * @param  array<mixed>  $request
     * @return array{status:string, response_status?:int, response?:array<string,mixed>}
     */
    public function begin(string $tenantId, string $scope, string $key, array $request): array
    {
        $hash = self::hash($request);
        $now = now();
        $inserted = DB::table('idempotency_keys')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'scope' => $scope,
            'idem_key' => $key,
            'request_hash' => $hash,
            'status' => 'in_flight',
            'locked_until' => $now->copy()->addSeconds((int) config('amplirail.payments.in_flight_lock_seconds')),
            'expires_at' => $now->copy()->addHours((int) config('amplirail.payments.idempotency_ttl_hours')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 1) {
            return ['status' => self::NEW];
        }

        $row = DB::table('idempotency_keys')->where('tenant_id', $tenantId)->where('scope', $scope)->where('idem_key', $key)->first();
        if ($row === null) {
            return ['status' => self::IN_FLIGHT];
        }
        if ($row->request_hash !== $hash) {
            return ['status' => self::CONFLICT];
        }
        if ($row->status === 'completed') {
            return [
                'status' => self::REPLAY,
                'response_status' => (int) $row->response_status,
                'response' => json_decode((string) $row->response_body, true) ?: [],
            ];
        }
        // A crashed worker leaves an expired in-flight lock: take it over.
        if ($row->locked_until !== null && now()->greaterThan($row->locked_until)) {
            $taken = DB::table('idempotency_keys')->where('id', $row->id)->where('status', 'in_flight')
                ->where('locked_until', $row->locked_until)
                ->update(['locked_until' => now()->addSeconds((int) config('amplirail.payments.in_flight_lock_seconds')), 'updated_at' => now()]);
            if ($taken === 1) {
                return ['status' => self::NEW];
            }
        }

        return ['status' => self::IN_FLIGHT];
    }

    /** @param array<string,mixed> $response */
    public function complete(string $tenantId, string $scope, string $key, int $status, array $response): void
    {
        DB::table('idempotency_keys')->where('tenant_id', $tenantId)->where('scope', $scope)->where('idem_key', $key)->update([
            'status' => 'completed',
            'response_status' => $status,
            'response_body' => json_encode($response, JSON_THROW_ON_ERROR),
            'locked_until' => null,
            'updated_at' => now(),
        ]);
    }

    public function release(string $tenantId, string $scope, string $key): void
    {
        DB::table('idempotency_keys')->where('tenant_id', $tenantId)->where('scope', $scope)->where('idem_key', $key)
            ->where('status', 'in_flight')->delete();
    }

    /** @param array<mixed> $request */
    public static function hash(array $request): string
    {
        return hash('sha256', json_encode(self::sortRecursive($request), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<mixed>  $a
     * @return array<mixed>
     */
    private static function sortRecursive(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::sortRecursive($v);
            }
        }
        if (! array_is_list($a)) {
            ksort($a);
        }

        return $a;
    }
}
