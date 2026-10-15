<?php

declare(strict_types=1);

namespace Modules\PlatformFees\Application;

use Illuminate\Support\Facades\Schema;
use Modules\PlatformFees\Contracts\PlatformFees;
use Modules\PlatformFees\Domain\PlatformFee;
use Modules\PlatformFees\Infrastructure\PlatformFeeRule;

final class PlatformFeeService implements PlatformFees
{
    public function forStatement(string $merchantId, string $currency, int $grossMinor, int $approvedCount): array
    {
        if (! Schema::hasTable('platform_fee_rules')) {
            return [];
        }
        $out = [];
        foreach (PlatformFeeRule::query()->where('merchant_id', $merchantId)->where('status', 'active')->where('currency', $currency)->orderBy('partner_name')->get() as $r) {
            $fee = PlatformFee::amount($grossMinor, $approvedCount, $r->percent_bps, $r->fixed_minor);
            if ($fee > 0) {
                $out[] = ['partner_key' => $r->partner_key, 'partner_name' => $r->partner_name, 'amount_minor' => $fee];
            }
        }

        return $out;
    }
}
