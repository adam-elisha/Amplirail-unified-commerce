<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/** Prefixed ULIDs (master document 4.2): org_, usr_, mer_, macc_, txn_, tat_, rf_, evt_, whk_, ... */
final class Ids
{
    public static function new(string $prefix): string
    {
        return $prefix.'_'.strtolower((string) Str::ulid());
    }

    /** Short random suffix for human-facing references (test MIDs, public merchant IDs). */
    public static function digits(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string) random_int(0, 9);
        }

        return $out;
    }
}
