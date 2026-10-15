<?php

declare(strict_types=1);

use App\Support\Amount;
use App\Support\Idempotency;
use App\Support\SensitiveData;
use Modules\Identity\Domain\Totp;
use Modules\Onboarding\Domain\IllegalTransition;
use Modules\Onboarding\Domain\MerchantStateMachine;
use Modules\Payments\Domain\DeclineCategory;
use Modules\Payments\Domain\IllegalTransactionTransition;
use Modules\Payments\Domain\TransactionStateMachine;
use Modules\Routing\Domain\EligibilityEngine;
use Modules\Webhooks\Application\WebhookUrlPolicy;

it('allows every legal onboarding transition and rejects at least one illegal event per state', function () {
    foreach (MerchantStateMachine::transitionEvents() as $event) {
        foreach (MerchantStateMachine::transitionsFor($event) as $from => $to) {
            $target = MerchantStateMachine::target($event, $from, 'boarding');
            expect(in_array($target, MerchantStateMachine::STATES, true))->toBeTrue("$event from $from");
        }
    }
    foreach (MerchantStateMachine::STATES as $state) {
        $illegal = collect(MerchantStateMachine::transitionEvents())->first(fn ($e) => ! MerchantStateMachine::canApply($e, $state));
        expect(fn () => MerchantStateMachine::target($illegal, $state, null))->toThrow(IllegalTransition::class);
    }
    expect(MerchantStateMachine::target('merchant.live', 'validation', null))->toBe('active');
    expect(fn () => MerchantStateMachine::target('merchant.live', 'test_mode', null))->toThrow(IllegalTransition::class);
    expect(MerchantStateMachine::target('documents.complete', 'needs_info', 'boarding'))->toBe('boarding');
    expect(MerchantStateMachine::target('documents.complete', 'needs_info', 'submitted'))->toBe('kyb_pending');
    expect(MerchantStateMachine::LIFECYCLE_EVENTS)->toHaveCount(12);
});

it('enforces the transaction state machine', function () {
    TransactionStateMachine::assert('authorized', 'captured');
    TransactionStateMachine::assert('captured', 'partially_refunded');
    expect(fn () => TransactionStateMachine::assert('voided', 'captured'))->toThrow(IllegalTransactionTransition::class);
    expect(fn () => TransactionStateMachine::assert('declined', 'refunded'))->toThrow(IllegalTransactionTransition::class);
    expect(DeclineCategory::fromCode('51', 'declined'))->toBe('insufficient_funds')->and(DeclineCategory::fromCode('00', 'approved'))->toBeNull();
});

it('matches the RFC 6238 SHA-1 test vector and refuses replays', function () {
    $secret = Totp::base32Encode('12345678901234567890');
    expect(Totp::codeAt($secret, intdiv(59, 30)))->toBe('287082')            // RFC 6238 Appendix B, last 6 of 94287082
        ->and(Totp::codeAt($secret, intdiv(1111111109, 30)))->toBe('081804'); // 07081804
    $step = Totp::verify($secret, '287082', 59);
    expect($step)->toBe(1)->and(Totp::verify($secret, '287082', 59, lastUsedStep: 1))->toBeNull();
});

it('detects card numbers with Luhn and masks secrets in text', function () {
    expect(SensitiveData::containsPan('pay 4111 1111 1111 1111 now'))->toBeTrue()
        ->and(SensitiveData::containsPan('order 1234567890123'))->toBeFalse()
        ->and(SensitiveData::findPan(['a' => ['b' => '5555-5555-5555-4444']]))->toBe('a.b')
        ->and(SensitiveData::findForbiddenKey(['banking' => ['Account_Number' => '1']]))->toBe('banking.Account_Number');
    $masked = SensitiveData::mask('key arsec_sbx_AbCdEf123456 card 4111111111111111 mail ana@x.example');
    expect($masked)->not->toContain('AbCdEf123456')->toContain('[PAN 1111]')->not->toContain('ana@x.example');
});

it('parses decimal amounts without floats', function () {
    expect(Amount::toMinor('1,234.56'))->toBe(123456)->and(Amount::toMinor('10'))->toBe(1000)->and(Amount::toMinor('0.5'))->toBe(50)
        ->and(Amount::toMinor('1.234'))->toBeNull()->and(Amount::toMinor('abc'))->toBeNull();
});

it('hashes idempotency requests independent of key order', function () {
    expect(Idempotency::hash(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]))->toBe(Idempotency::hash(['a' => ['x' => 1, 'y' => 2], 'b' => 1]));
});

it('blocks SSRF targets for webhooks', function () {
    $policy = new WebhookUrlPolicy(false);
    expect($policy->validate('http://example.com/h'))->toContain('HTTPS')
        ->and($policy->validate('https://127.0.0.1/h'))->toContain('private')
        ->and($policy->validate('https://10.0.0.5/h'))->toContain('private')
        ->and($policy->validate('https://169.254.169.254/latest'))->toContain('private')
        ->and($policy->validate('https://user:pw@example.com/h'))->toContain('credentials');
});

it('evaluates each eligibility rule type with explainable failures', function (array $rule, array $profile, ?string $failing) {
    $base = ['country' => 'US', 'region' => 'FL', 'mcc' => '5812', 'channels' => ['ecommerce'], 'recurring' => false, 'monthly_volume_minor' => 1000000,
        'max_ticket_minor' => 10000, 'chargeback_ratio_bps' => 20, 'currency' => 'USD', 'reserve_bps' => 0, 'documents' => ['bank_letter']];
    $out = (new EligibilityEngine)->evaluate(array_merge($base, $profile), ['routes' => [['code' => 'r', 'connector' => 'nmi_bridge', 'rules' => $rule]]]);
    if ($failing === null) {
        expect($out['eligible'])->toHaveCount(1);
    } else {
        expect($out['ineligible'][0]['failed_rules'][0]['rule'])->toBe($failing);
    }
})->with([
    'geography' => [['countries' => ['US']], ['country' => 'CA'], 'geography'],
    'blocked region' => [['regions_blocked' => ['FL']], [], 'geography'],
    'mcc blocked' => [['mcc_blocked' => ['5812']], [], 'mcc_blocked'],
    'mcc restricted' => [['mcc_restricted' => ['5812']], [], 'mcc_restricted'],
    'restricted allowed' => [['mcc_restricted' => ['5812'], 'allow_restricted' => true], [], null],
    'channels' => [['channels' => ['ecommerce']], ['channels' => ['ecommerce', 'moto']], 'channels'],
    'card present' => [['card_present' => false], ['channels' => ['card_present']], 'card_present'],
    'recurring' => [['recurring_allowed' => false], ['recurring' => true], 'recurring'],
    'volume' => [['max_monthly_volume_minor' => 100], [], 'monthly_volume'],
    'ticket' => [['max_ticket_minor' => 100], [], 'ticket_size'],
    'chargebacks' => [['max_chargeback_ratio_bps' => 10], [], 'chargeback_profile'],
    'no history passes chargebacks' => [['max_chargeback_ratio_bps' => 10], ['chargeback_ratio_bps' => null], null],
    'currency' => [['currencies' => ['EUR']], [], 'currency'],
    'reserve' => [['min_reserve_bps' => 500], [], 'reserve'],
    'documents' => [['required_documents' => ['bank_letter', 'government_id']], [], 'documents'],
]);

it('orders eligible routes by priority and reports precedence order of failures', function () {
    $engine = new EligibilityEngine;
    $profile = ['country' => 'CA', 'region' => null, 'mcc' => '7995', 'channels' => ['ecommerce'], 'recurring' => false, 'monthly_volume_minor' => 1,
        'max_ticket_minor' => 1, 'chargeback_ratio_bps' => null, 'currency' => 'USD', 'reserve_bps' => null, 'documents' => []];
    $out = $engine->evaluate($profile, ['routes' => [
        ['code' => 'b', 'connector' => 'x', 'priority' => 2, 'rules' => []],
        ['code' => 'a', 'connector' => 'x', 'priority' => 1, 'rules' => []],
        ['code' => 'c', 'connector' => 'x', 'priority' => 1, 'rules' => ['countries' => ['US'], 'mcc_blocked' => ['7995']]],
    ]]);
    expect(array_column($out['eligible'], 'code'))->toBe(['a', 'b'])
        ->and(array_column($out['ineligible'][0]['failed_rules'], 'rule'))->toBe(['geography', 'mcc_blocked']);
});
