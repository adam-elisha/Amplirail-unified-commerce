<?php

declare(strict_types=1);

namespace Modules\Ledger\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * Append-only (AGENTS.md rule 5). Debit positive, credit negative; each journal sums to zero per currency.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $journal_id
 * @property string $account
 * @property int $amount_minor
 * @property string $currency
 * @property string $source_type
 * @property string $source_id
 * @property string $environment
 * @property Carbon $created_at
 */
final class LedgerPosting extends Model
{
    use BelongsToTenant;

    protected $table = 'ledger_postings';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['amount_minor' => 'integer'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('ledger_postings is append-only.'));
        self::deleting(fn () => throw new \LogicException('ledger_postings is append-only.'));
    }
}
