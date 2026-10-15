<?php
declare(strict_types=1);
namespace AmpliRail\Http;

/** Injected so connectors are testable and timeouts and circuit breakers live in one place (Section 4.5 rule 7). */
interface HttpClient
{
    /** @return array{status:int, body:string} */
    public function post(string $url, array $headers, string $body, int $timeoutMs): array;
}
