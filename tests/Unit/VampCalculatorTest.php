<?php

declare(strict_types=1);

use Modules\Monitoring\Domain\VampCalculator;

it('counts a sale with both a fraud report and a dispute once', function (): void {
    expect(VampCalculator::ratio(['t1', 't2'], ['t2', 't3'], 0, 200))->toBe(['count' => 3, 'ratio_bps' => 150]);
});

it('flags excessive only with the minimum event count', function (): void {
    expect(VampCalculator::merchantStatus(150, 1500))->toBe('excessive')
        ->and(VampCalculator::merchantStatus(150, 1499))->toBe('over_ratio_below_minimum')
        ->and(VampCalculator::merchantStatus(112, 10))->toBe('warning')
        ->and(VampCalculator::merchantStatus(111, 10))->toBe('ok')
        ->and(VampCalculator::merchantStatus(200, 5000, 'other'))->toBe('warning');
});

it('rates the portfolio against acquirer thresholds', function (): void {
    expect(VampCalculator::portfolioStatus(49))->toBe('ok')
        ->and(VampCalculator::portfolioStatus(50))->toBe('above_standard')
        ->and(VampCalculator::portfolioStatus(70))->toBe('excessive');
});
