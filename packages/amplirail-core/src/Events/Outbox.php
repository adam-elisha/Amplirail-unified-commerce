<?php
declare(strict_types=1);
namespace AmpliRail\Events;

/** Transactional outbox (rule 6). Production writes outbox_events in the same DB transaction; a relay publishes to the event bus. */
final class Outbox
{
    /** @var array<int,array{type:string,payload:array,published:bool}> */
    public array $events = [];
    public function add(string $type, array $payload): void { $this->events[] = ['type' => $type, 'payload' => $payload, 'published' => false]; }
    public function types(): array { return array_column($this->events, 'type'); }
    /** Relay: hands unpublished events to a publisher callback, marks them published only after success. */
    public function relay(callable $publish): int
    {
        $n = 0;
        foreach ($this->events as $i => $e) { if (!$e['published']) { $publish($e); $this->events[$i]['published'] = true; $n++; } }
        return $n;
    }
}
