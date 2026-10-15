<?php

declare(strict_types=1);

use Modules\Velocity\Contracts\VelocityInput;
use Modules\Velocity\Domain\VelocityRule;

function vin(string $token = 'tok', ?string $bin = '41472012', ?string $brand = 'visa', int $amount = 5000): VelocityInput
{
    return new VelocityInput('mer_1', 'live', $amount, 'USD', $token, $bin, $brand);
}

it('limits attempts per card for one merchant', function (): void {
    $r = new VelocityRule('v1', 'card', 'merchant', 'mer_1', 'card', 'count', 60, 5, null, 'live', 'block');
    expect($r->applies(vin()))->toBeTrue()->and($r->fits(4, vin()))->toBeTrue()->and($r->fits(5, vin()))->toBeFalse();
});

it('limits amount per BIN and ignores other currencies', function (): void {
    $r = new VelocityRule('v2', 'bin', 'bin', '414720', 'bin', 'amount', 1440, 10000, 'USD', 'any', 'block');
    expect($r->fits(5000, vin()))->toBeTrue()->and($r->fits(5001, vin()))->toBeFalse()
        ->and($r->applies(new VelocityInput('mer_1', 'live', 1, 'EUR', 'tok', '41472012', 'visa')))->toBeFalse();
});

it('applies processor rules only to that processor and brand rules only to that brand', function (): void {
    $p = new VelocityRule('v3', 'nmi', 'connector', 'nmi_bridge', 'total', 'count', 60, 1, null, 'any', 'block');
    $b = new VelocityRule('v4', 'mc', 'card_brand', 'mastercard', 'total', 'count', 60, 1, null, 'any', 'block');
    expect($p->applies(vin()))->toBeFalse()->and($p->applies(vin(), 'nmi_bridge'))->toBeTrue()->and($b->applies(vin()))->toBeFalse();
});

it('keeps separate counters per card', function (): void {
    $r = new VelocityRule('v1', 'card', 'merchant', 'mer_1', 'card', 'count', 60, 5, null, 'live', 'block');
    expect($r->counterKey(vin('a'), 1000))->not->toBe($r->counterKey(vin('b'), 1000));
});
