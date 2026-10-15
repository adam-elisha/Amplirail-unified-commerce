<?php
declare(strict_types=1);
namespace AmpliRail\Screening;

use AmpliRail\Support\Clock;

/** Launch-slice screening: per-MID limits and simple velocity. Full rules engine follows in Phase 1. */
final class Screening
{
    private array $seen = []; // tokenHash => [timestamps]

    public function __construct(private readonly Clock $clock) {}

    /** @param array{max_ticket_minor:int, max_per_token_per_hour:int, blocked_tokens:array<int,string>} $rules
     *  @return array{allow:bool, reason?:string} */
    public function check(string $paymentToken, int $amountMinor, array $rules): array
    {
        if (in_array($paymentToken, $rules['blocked_tokens'] ?? [], true)) return ['allow' => false, 'reason' => 'blocked_token'];
        if ($amountMinor > $rules['max_ticket_minor']) return ['allow' => false, 'reason' => 'over_max_ticket'];
        $h = hash('sha256', $paymentToken); $now = $this->clock->now()->getTimestamp();
        $this->seen[$h] = array_values(array_filter($this->seen[$h] ?? [], fn($t) => $t > $now - 3600));
        if (count($this->seen[$h]) >= $rules['max_per_token_per_hour']) return ['allow' => false, 'reason' => 'velocity'];
        $this->seen[$h][] = $now;
        return ['allow' => true];
    }
}
