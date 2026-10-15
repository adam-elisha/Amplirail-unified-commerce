<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Events\Application\OutboxRelay;

/** Long-running relay (run under supervisor/ECS) or a single pass (--once, used by the scheduler as a safety net). */
final class OutboxRelayCommand extends Command
{
    protected $signature = 'amplirail:outbox:relay {--once : Run one pass and exit} {--sleep=250 : Milliseconds between idle polls} {--max-seconds=3600 : Exit after this long so the supervisor restarts fresh}';

    protected $description = 'Publish committed outbox events and run internal consumers (webhook fan-out, onboarding automation)';

    public function handle(): int
    {
        $deadline = time() + (int) $this->option('max-seconds');
        do {
            // Resolve per pass: forgetScopedInstances() below replaces the scoped TenantScope, and a relay built
            // before that would set tenant context on the old instance while models read the new one.
            $relay = app(OutboxRelay::class);
            $n = $relay->relayAll((int) config('amplirail.outbox.batch_size'));
            if ($n > 0) {
                $this->line("published $n");
            } elseif (! $this->option('once')) {
                usleep(max(50, (int) $this->option('sleep')) * 1000);
            }
            app()->forgetScopedInstances();
        } while (! $this->option('once') && time() < $deadline);

        return self::SUCCESS;
    }
}
