<?php
declare(strict_types=1);
namespace AmpliRail\Payments;

/** Rule 5. Production: Redis SET NX with TTL plus a unique DB index (tenant_id, idempotency_key). */
final class IdempotencyStore
{
    private array $done = [];      // key => [requestHash, response]
    private array $inFlight = [];

    /** @return array{status:string, response?:mixed} status: new | replay | conflict | in_flight */
    public function begin(string $tenant, string $key, string $requestHash): array
    {
        $k = $tenant . '|' . $key;
        if (isset($this->done[$k])) {
            return $this->done[$k][0] === $requestHash ? ['status' => 'replay', 'response' => $this->done[$k][1]] : ['status' => 'conflict'];
        }
        if (isset($this->inFlight[$k])) return ['status' => 'in_flight'];
        $this->inFlight[$k] = true;
        return ['status' => 'new'];
    }

    public function complete(string $tenant, string $key, string $requestHash, mixed $response): void
    {
        $k = $tenant . '|' . $key; unset($this->inFlight[$k]); $this->done[$k] = [$requestHash, $response];
    }

    public function release(string $tenant, string $key): void { unset($this->inFlight[$tenant . '|' . $key]); }
}
