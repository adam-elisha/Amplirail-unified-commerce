<?php

declare(strict_types=1);

namespace App\Support;

/** Parses human-entered decimal amounts ("1,234.56") into integer minor units without floats (AGENTS.md rule 4). */
final class Amount
{
    public static function toMinor(mixed $input, int $decimals = 2): ?int
    {
        $s = str_replace([',', ' ', '$', '€', '£'], '', trim((string) $input));
        if ($s === '' || ! preg_match('/^(\d{1,13})(?:\.(\d{1,'.$decimals.'}))?$/', $s, $m)) {
            return null;
        }

        return (int) $m[1] * (10 ** $decimals) + (int) str_pad($m[2] ?? '', $decimals, '0');
    }
}
