<?php

declare(strict_types=1);

namespace Modules\Events\Contracts;

/** Read side of the outbox: event timelines for Merchant 360 and transaction detail. */
interface EventLog
{
    /**
     * @param  list<string>  $subjectIds
     * @return list<array<string,mixed>> envelopes, oldest first
     */
    public function forSubjects(array $subjectIds, int $limit = 200): array;
}
