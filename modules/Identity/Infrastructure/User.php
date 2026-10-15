<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure;

use App\Support\Crypto\EncryptedField;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Portal user. portal = admin | merchant. Passwords use Argon2id (config/hashing.php).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string $portal
 * @property string $status invited | active | disabled
 * @property string|null $totp_secret encrypted; admins only
 * @property Carbon|null $totp_confirmed_at
 * @property int|null $totp_last_step
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property Collection<int, Membership> $memberships
 */
final class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'totp_secret'];

    /**
     * Defaults so in-memory models behave like loaded rows under strict mode.
     *
     * @var array<string,mixed>
     */
    protected $attributes = [
        'remember_token' => null, 'totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null,
        'failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'totp_secret' => EncryptedField::class,
            'totp_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function primaryMembership(): ?Membership
    {
        return $this->memberships()->where('status', 'active')->orderBy('created_at')->first();
    }
}
