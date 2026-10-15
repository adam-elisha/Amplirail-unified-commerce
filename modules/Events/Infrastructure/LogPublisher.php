<?php

declare(strict_types=1);

namespace Modules\Events\Infrastructure;

use Illuminate\Support\Facades\Log;
use Modules\Events\Contracts\EventPublisher;

/** Local and development: logs the event type and IDs (never the data payload). */
final class LogPublisher implements EventPublisher
{
    public function publish(array $envelope): void
    {
        Log::info('event.published', ['event_id' => $envelope['id'], 'type' => $envelope['type'], 'subject' => $envelope['subject']]);
    }
}
