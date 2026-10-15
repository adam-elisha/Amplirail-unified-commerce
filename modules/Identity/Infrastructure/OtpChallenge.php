<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Emailed one-time code (merchant portal sign-in and step-up). Only the hash is stored.
 *
 * @property string $id
 * @property string $user_id
 * @property string $purpose sign_in | step_up
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property int $attempts
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 */
final class OtpChallenge extends Model
{
    protected $table = 'otp_challenges';

    protected $keyType = 'string';

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'created_at' => 'datetime'];
}
