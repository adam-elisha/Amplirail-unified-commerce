<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler (run "php artisan schedule:work" in one container/process, or cron "* * * * * php artisan schedule:run").
 * The long-running outbox relay and queue workers run separately under supervisor/ECS (see deploy/).
 */
Schedule::command('amplirail:outbox:relay --once')->everyMinute()->withoutOverlapping()->runInBackground();
Schedule::command('amplirail:webhooks:retry')->everyMinute()->withoutOverlapping();
Schedule::command('amplirail:partitions:ensure --months=3')->dailyAt('02:10')->onOneServer();
Schedule::command('amplirail:statements:run')->weeklyOn(1, '06:00')->timezone('UTC')->onOneServer();
Schedule::command('amplirail:prune')->dailyAt('03:20')->onOneServer();
