<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\Partitions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** AR-161: create next months' partitions in advance. Run with the owner/migration database role. */
final class PartitionsEnsureCommand extends Command
{
    protected $signature = 'amplirail:partitions:ensure {--months=3} {--database= : Connection name (defaults to DB_MIGRATION_CONNECTION or the default)}';

    protected $description = 'Create monthly partitions ahead of time (PostgreSQL)';

    public function handle(): int
    {
        $connection = (string) ($this->option('database') ?: config('amplirail.migration_connection'));
        $created = Partitions::ensure(DB::connection($connection), (int) $this->option('months'));
        $this->line($created === [] ? 'partitions up to date' : 'created: '.implode(', ', $created));

        return self::SUCCESS;
    }
}
