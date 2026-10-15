<?php

declare(strict_types=1);

namespace Modules\Events\Infrastructure;

use App\Support\Actor;
use App\Support\Ids;
use App\Support\RequestContext;
use App\Support\SensitiveData;
use Illuminate\Database\DatabaseManager;
use Modules\Events\Contracts\EventLog;
use Modules\Events\Contracts\Outbox;
use Modules\Tenancy\Contracts\TenantScope;

final class DatabaseOutbox implements EventLog, Outbox
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantScope $tenants,
        private readonly RequestContext $request,
    ) {}

    public function record(string $type, string $subjectType, string $subjectId, array $data, ?Actor $actor = null, ?string $environment = null, ?string $orgPath = null): string
    {
        if ($this->db->connection()->transactionLevel() < 1) {
            throw new \LogicException("Outbox event $type must be written inside the transaction that caused it.");
        }
        if (($path = SensitiveData::findPan($data)) !== null) {
            throw new \LogicException("Outbox event $type carries a card-number-like value at $path; refused.");
        }
        $ctx = $this->tenants->current();
        $id = Ids::new('evt');

        OutboxEvent::query()->create([
            'event_id' => $id,
            'type' => $type,
            'version' => 1,
            'environment' => $environment ?? $ctx->environment ?? 'platform',
            'org_path' => $orgPath ?? $ctx->orgPath,
            'actor' => ($actor ?? $ctx->actor)->toArray(),
            'correlation_id' => $this->request->correlationId(),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'merchant_id' => is_string($data['merchant_id'] ?? null) ? $data['merchant_id'] : ($subjectType === 'merchant' ? $subjectId : null),
            'data' => $data,
            'occurred_at' => now(),
            'attempts' => 0,
        ]);

        return $id;
    }

    public function forSubjects(array $subjectIds, int $limit = 200): array
    {
        if ($subjectIds === []) {
            return [];
        }

        return array_values(OutboxEvent::query()
            ->where(fn ($q) => $q->whereIn('subject_id', $subjectIds)->orWhereIn('merchant_id', $subjectIds))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (OutboxEvent $e) => $e->envelope())
            ->all());
    }
}
