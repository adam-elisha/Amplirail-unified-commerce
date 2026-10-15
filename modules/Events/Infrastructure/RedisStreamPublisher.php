<?php

declare(strict_types=1);

namespace Modules\Events\Infrastructure;

use Illuminate\Support\Facades\Redis;
use Modules\Events\Contracts\EventPublisher;

/** Redis Streams publisher (launch event bus). One XADD per event; downstream readers use consumer groups. */
final class RedisStreamPublisher implements EventPublisher
{
    public function __construct(private readonly string $stream) {}

    public function publish(array $envelope): void
    {
        Redis::connection()->command('xadd', [
            $this->stream, '*',
            'type', (string) $envelope['type'],
            'envelope', json_encode($envelope, JSON_THROW_ON_ERROR),
        ]);
    }
}
