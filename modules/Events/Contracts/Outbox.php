<?php

declare(strict_types=1);

namespace Modules\Events\Contracts;

use App\Support\Actor;

/**
 * Transactional outbox (AGENTS.md rule 7). record() MUST be called inside the same database transaction as the state
 * change it describes; it throws otherwise, so commit and publish can never disagree.
 */
interface Outbox
{
    /**
     * @param  array<string,mixed>  $data  canonical data only: no PAN, no secrets, documents by ID only
     * @return string event ID (evt_...)
     */
    public function record(
        string $type,
        string $subjectType,
        string $subjectId,
        array $data,
        ?Actor $actor = null,
        ?string $environment = null,
        ?string $orgPath = null,
    ): string;
}
