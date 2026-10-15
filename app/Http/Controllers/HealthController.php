<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Load balancer and deploy smoke test target. Reveals no configuration. */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $db = 'ok';
        } catch (Throwable) {
            $db = 'down';
        }
        $version = trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev';

        return response()->json(['status' => $db === 'ok' ? 'ok' : 'degraded', 'database' => $db, 'version' => $version], $db === 'ok' ? 200 : 503);
    }
}
