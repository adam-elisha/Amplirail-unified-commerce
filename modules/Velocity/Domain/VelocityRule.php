<?php

declare(strict_types=1);

namespace Modules\Velocity\Domain;

use Modules\Velocity\Contracts\VelocityInput;

/** One limit. Pure logic: which attempts it applies to, which counter it uses, and whether a new attempt fits. */
final class VelocityRule
{
    public const SCOPES = ['portfolio', 'merchant', 'connector', 'bin', 'card_brand'];

    public const DIMENSIONS = ['total', 'card', 'bin'];

    public const METRICS = ['count', 'amount', 'single_amount'];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $scope,
        public readonly ?string $scopeValue,
        public readonly string $dimension,
        public readonly string $metric,
        public readonly int $windowMinutes,
        public readonly int $limit,
        public readonly ?string $currency,
        public readonly string $environment,
        public readonly string $action,
    ) {}

    public function applies(VelocityInput $in, ?string $connector = null): bool
    {
        if ($this->environment !== 'any' && $this->environment !== $in->environment) {
            return false;
        }
        if ($this->currency !== null && in_array($this->metric, ['amount', 'single_amount'], true) && $this->currency !== $in->currency) {
            return false;
        }
        if ($this->dimension === 'bin' && ($in->bin === null || $in->bin === '')) {
            return false;
        }

        return match ($this->scope) {
            'portfolio' => true,
            'merchant' => $this->scopeValue === $in->merchantId,
            'connector' => $connector !== null && $this->scopeValue === $connector,
            'bin' => $in->bin !== null && $this->scopeValue !== null && str_starts_with($in->bin, $this->scopeValue),
            'card_brand' => $in->brand !== null && $this->scopeValue !== null && strcasecmp($in->brand, $this->scopeValue) === 0,
            default => false,
        };
    }

    /** Counter key: rule, the merchant when not portfolio-wide, the dimension value, and the time bucket. */
    public function counterKey(VelocityInput $in, int $now): string
    {
        $who = $this->scope === 'merchant' ? $in->merchantId : 'shared'; // other scopes count across all merchants
        $dim = match ($this->dimension) {
            'card' => $in->merchantId.':'.$in->cardKey,
            'bin' => (string) $in->bin,
            default => 'total',
        };
        $bucket = intdiv($now, max(60, $this->windowMinutes * 60));

        return 'vel:'.$this->id.':'.$who.':'.hash('sha256', $dim).':'.$bucket;
    }

    /** Whether one more attempt fits, given the current counter value. */
    public function fits(int $current, VelocityInput $in): bool
    {
        return match ($this->metric) {
            'single_amount' => $in->amountMinor <= $this->limit,
            'amount' => $current + $in->amountMinor <= $this->limit,
            default => $current + 1 <= $this->limit,
        };
    }

    public function increment(VelocityInput $in): int
    {
        return $this->metric === 'amount' ? $in->amountMinor : 1;
    }

    public function blocks(): bool
    {
        return $this->action === 'block';
    }
}
