<?php

declare(strict_types=1);

namespace Modules\MerchantProducts\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $merchant_id
 * @property string $environment
 * @property int $amount_minor
 * @property string $currency
 * @property string $reference
 * @property string|null $description
 * @property string $token
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property string $status
 * @property string|null $transaction_id
 * @property array<string,string>|null $send_to
 * @property Carbon $created_at
 */
final class PaymentLink extends Model
{
    use BelongsToTenant;

    protected $table = 'payment_links';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['token', 'token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'send_to' => 'array', 'amount_minor' => 'integer'];

    public function merchantScopeColumn(): string
    {
        return 'merchant_id';
    }

    public function url(): string
    {
        return rtrim((string) config('amplirail.public_urls.pay'), '/').'/l/'.$this->token;
    }

    /** @return array<string,mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id, 'object' => 'payment_link', 'environment' => $this->environment, 'url' => $this->url(),
            'amount_minor' => $this->amount_minor, 'currency' => $this->currency, 'reference' => $this->reference,
            'description' => $this->description, 'status' => $this->status, 'transaction_id' => $this->transaction_id,
            'expires_at' => $this->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
