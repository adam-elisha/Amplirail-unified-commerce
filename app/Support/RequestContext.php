<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-request (or per-job) correlation data. Bound as a scoped singleton so Octane workers never leak it between requests.
 */
final class RequestContext
{
    private ?string $correlationId = null;

    private ?string $requestId = null;

    public function correlationId(): string
    {
        return $this->correlationId ??= Ids::new('corr');
    }

    public function requestId(): string
    {
        return $this->requestId ??= Ids::new('req');
    }

    public function setCorrelationId(string $id): void
    {
        // Accept caller-supplied IDs only in a safe shape; otherwise mint our own.
        $this->correlationId = preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $id) ? $id : Ids::new('corr');
    }
}
