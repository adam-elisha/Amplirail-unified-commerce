<?php

declare(strict_types=1);

use AmpliRail\Routing\MerchantAccount;
use Modules\PlatformFees\Domain\PlatformFee;
use Modules\RoutePolicy\Contracts\RouteContext;
use Modules\RoutePolicy\Domain\BinPreference;
use Modules\RoutePolicy\Domain\MidCap;

it('orders only the merchant\'s own MIDs, preferred processors first', function (): void {
    $mids = [new MerchantAccount('a', 'mer', 'nmi_bridge', 'USD', 1), new MerchantAccount('b', 'mer', 'gattera_bridge', 'USD', 2)];
    $p = new BinPreference('p', null, null, 'visa', 'debit', null, null, ['gattera_bridge'], 100);
    expect($p->matches(new RouteContext('mer', 'live', 1000, 'USD', '41472012', 'visa', 'debit'), []))->toBeTrue()
        ->and(array_map(fn ($m) => $m->id, $p->apply($mids)))->toBe(['b', 'a']);
});

it('respects pause and caps', function (): void {
    expect(MidCap::allows(false, null, 10000, 0, 9000, 1000))->toBeTrue()
        ->and(MidCap::allows(false, null, 10000, 0, 9001, 1000))->toBeFalse()
        ->and(MidCap::allows(true, null, null, 0, 0, 1))->toBeFalse();
});

it('computes the partner markup half-up', function (): void {
    expect(PlatformFee::amount(138455, 12, 25, 10))->toBe(466);
});
