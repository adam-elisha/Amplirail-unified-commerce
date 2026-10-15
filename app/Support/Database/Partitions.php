<?php

declare(strict_types=1);

namespace App\Support\Database;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;

/**
 * Monthly range partitions for the high-volume tables (master document 4.5, AR-161). PostgreSQL only.
 * A DEFAULT partition catches rows if the scheduled job ever falls behind, so inserts never fail.
 */
final class Partitions
{
    public const TABLES = ['transactions', 'transaction_attempts', 'ledger_postings', 'outbox_events'];

    /** @return list<string> partitions created */
    public static function ensure(Connection $db, int $monthsAhead = 3, ?CarbonImmutable $from = null): array
    {
        if ($db->getDriverName() !== 'pgsql') {
            return [];
        }
        $created = [];
        $start = ($from ?? CarbonImmutable::now('UTC'))->startOfMonth();
        foreach (self::TABLES as $table) {
            for ($i = 0; $i <= $monthsAhead; $i++) {
                $month = $start->addMonths($i);
                $name = $table.'_'.$month->format('Y_m');
                $exists = $db->selectOne('select to_regclass(?) as r', ['public.'.$name]);
                if ($exists !== null && $exists->r !== null) {
                    continue;
                }
                $db->statement(sprintf(
                    "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                    $name, $table, $month->format('Y-m-d'), $month->addMonth()->format('Y-m-d'),
                ));
                $created[] = $name;
            }
        }

        return $created;
    }
}
