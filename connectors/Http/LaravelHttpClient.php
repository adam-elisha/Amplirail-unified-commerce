<?php

declare(strict_types=1);

namespace Connectors\Http;

use AmpliRail\Http\HttpClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Core HttpClient implementation: per-call timeout and a per-host circuit breaker (master document 4.5, 7).
 * When the breaker is open the call fails fast; the connector maps that to a retryable "provider unreachable" error.
 */
final class LaravelHttpClient implements HttpClient
{
    public function __construct(
        private readonly Cache $cache,
        private readonly int $failureThreshold = 5,
        private readonly int $openSeconds = 30,
    ) {}

    /**
     * @param  array<string,string>  $headers
     * @return array{status:int, body:string}
     */
    public function post(string $url, array $headers, string $body, int $timeoutMs): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $key = 'circuit:'.$host;
        if ((int) $this->cache->get($key.':open_until', 0) > time()) {
            throw new RuntimeException("Circuit open for $host");
        }
        try {
            $response = Http::withHeaders($headers)
                ->timeout(max(1, intdiv($timeoutMs, 1000)))
                ->connectTimeout(3)
                ->withBody($body, $headers['Content-Type'] ?? 'application/octet-stream')
                ->post($url);
        } catch (\Throwable $e) {
            $this->recordFailure($key);
            throw $e;
        }
        if ($response->serverError()) {
            $this->recordFailure($key);
        } else {
            $this->cache->forget($key.':failures');
        }

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    private function recordFailure(string $key): void
    {
        $failures = (int) $this->cache->increment($key.':failures');
        if ($failures >= $this->failureThreshold) {
            $this->cache->put($key.':open_until', time() + $this->openSeconds, $this->openSeconds);
            $this->cache->forget($key.':failures');
        }
    }
}
