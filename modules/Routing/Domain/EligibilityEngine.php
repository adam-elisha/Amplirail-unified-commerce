<?php

declare(strict_types=1);

namespace Modules\Routing\Domain;

/**
 * Processor eligibility (AR-170): pure, explainable rule evaluation. No framework code.
 * Rules are evaluated in a fixed precedence order; every failing rule is reported with expected vs actual so staff
 * know exactly why a route cannot take the merchant. Eligible routes are ordered by priority (then code for ties).
 *
 * Route rule keys (all optional): countries, regions_blocked, mcc_blocked, mcc_restricted, allow_restricted, channels,
 * card_present, recurring_allowed, max_monthly_volume_minor, max_ticket_minor, max_chargeback_ratio_bps, currencies,
 * min_reserve_bps, required_documents.
 */
final class EligibilityEngine
{
    public const RULE_ORDER = [
        'geography', 'mcc_blocked', 'mcc_restricted', 'channels', 'card_present', 'recurring', 'monthly_volume',
        'ticket_size', 'chargeback_profile', 'currency', 'reserve', 'documents',
    ];

    /**
     * @param  array{country:string, region:?string, mcc:string, channels:list<string>, recurring:bool, monthly_volume_minor:int,
     *               max_ticket_minor:int, chargeback_ratio_bps:?int, currency:string, reserve_bps:?int, documents:list<string>}  $profile
     * @param  array{routes: list<array<string,mixed>>}  $ruleSet
     * @return array{eligible: list<array<string,mixed>>, ineligible: list<array<string,mixed>>}
     */
    public function evaluate(array $profile, array $ruleSet): array
    {
        $eligible = [];
        $ineligible = [];
        foreach ($ruleSet['routes'] as $route) {
            $rules = (array) ($route['rules'] ?? []);
            $failed = [];
            $passed = [];
            foreach (self::RULE_ORDER as $rule) {
                $outcome = $this->check($rule, $rules, $profile);
                if ($outcome === null) {
                    continue; // rule not configured for this route
                }
                if ($outcome === true) {
                    $passed[] = $rule;
                } else {
                    $failed[] = ['rule' => $rule] + $outcome;
                }
            }
            $row = [
                'code' => (string) $route['code'],
                'name' => (string) ($route['name'] ?? $route['code']),
                'connector' => (string) ($route['connector'] ?? ''),
                'priority' => (int) ($route['priority'] ?? 100),
                'passed_rules' => $passed,
            ];
            if ($failed === []) {
                $eligible[] = $row;
            } else {
                $ineligible[] = $row + ['failed_rules' => $failed];
            }
        }
        usort($eligible, fn ($a, $b) => [$a['priority'], $a['code']] <=> [$b['priority'], $b['code']]);

        return ['eligible' => $eligible, 'ineligible' => $ineligible];
    }

    /**
     * @param  array<string,mixed>  $r
     * @param  array<string,mixed>  $p
     * @return true|null|array{message:string, expected:mixed, actual:mixed}
     */
    private function check(string $rule, array $r, array $p): true|null|array
    {
        switch ($rule) {
            case 'geography':
                if (! isset($r['countries']) && ! isset($r['regions_blocked'])) {
                    return null;
                }
                if (isset($r['countries']) && ! in_array($p['country'], (array) $r['countries'], true)) {
                    return $this->fail('Country not supported by this route', $r['countries'], $p['country']);
                }
                if ($p['region'] !== null && in_array($p['region'], (array) ($r['regions_blocked'] ?? []), true)) {
                    return $this->fail('State or region blocked on this route', $r['regions_blocked'], $p['region']);
                }

                return true;
            case 'mcc_blocked':
                if (! isset($r['mcc_blocked'])) {
                    return null;
                }

                return in_array($p['mcc'], (array) $r['mcc_blocked'], true)
                    ? $this->fail('MCC is prohibited on this route', $r['mcc_blocked'], $p['mcc']) : true;
            case 'mcc_restricted':
                if (! isset($r['mcc_restricted'])) {
                    return null;
                }

                return in_array($p['mcc'], (array) $r['mcc_restricted'], true) && ! ($r['allow_restricted'] ?? false)
                    ? $this->fail('Restricted MCC needs a route that accepts restricted categories', 'allow_restricted', $p['mcc']) : true;
            case 'channels':
                if (! isset($r['channels'])) {
                    return null;
                }
                $missing = array_values(array_diff(array_diff($p['channels'], ['card_present']), (array) $r['channels']));

                return $missing === [] ? true : $this->fail('Requested channels not supported', $r['channels'], $missing);
            case 'card_present':
                if (! array_key_exists('card_present', $r)) {
                    return null;
                }

                return in_array('card_present', $p['channels'], true) && ! $r['card_present']
                    ? $this->fail('Card-present processing not supported', false, true) : true;
            case 'recurring':
                if (! array_key_exists('recurring_allowed', $r)) {
                    return null;
                }

                return $p['recurring'] && ! $r['recurring_allowed']
                    ? $this->fail('Recurring billing not allowed', false, true) : true;
            case 'monthly_volume':
                if (! isset($r['max_monthly_volume_minor'])) {
                    return null;
                }

                return $p['monthly_volume_minor'] > (int) $r['max_monthly_volume_minor']
                    ? $this->fail('Expected monthly volume above route limit', (int) $r['max_monthly_volume_minor'], $p['monthly_volume_minor']) : true;
            case 'ticket_size':
                if (! isset($r['max_ticket_minor'])) {
                    return null;
                }

                return $p['max_ticket_minor'] > (int) $r['max_ticket_minor']
                    ? $this->fail('Highest ticket above route limit', (int) $r['max_ticket_minor'], $p['max_ticket_minor']) : true;
            case 'chargeback_profile':
                if (! isset($r['max_chargeback_ratio_bps'])) {
                    return null;
                }
                if ($p['chargeback_ratio_bps'] === null) {
                    return true; // no processing history
                }

                return $p['chargeback_ratio_bps'] > (int) $r['max_chargeback_ratio_bps']
                    ? $this->fail('Chargeback ratio above route limit', (int) $r['max_chargeback_ratio_bps'], $p['chargeback_ratio_bps']) : true;
            case 'currency':
                if (! isset($r['currencies'])) {
                    return null;
                }

                return in_array($p['currency'], (array) $r['currencies'], true)
                    ? true : $this->fail('Settlement currency not supported', $r['currencies'], $p['currency']);
            case 'reserve':
                if (! isset($r['min_reserve_bps'])) {
                    return null;
                }

                return ($p['reserve_bps'] ?? 0) < (int) $r['min_reserve_bps']
                    ? $this->fail('Route requires a higher reserve', (int) $r['min_reserve_bps'], $p['reserve_bps'] ?? 0) : true;
            case 'documents':
                if (! isset($r['required_documents'])) {
                    return null;
                }
                $missing = array_values(array_diff((array) $r['required_documents'], $p['documents']));

                return $missing === [] ? true : $this->fail('Processor document requirements not met', $r['required_documents'], $missing);
        }

        return null;
    }

    /** @return array{message:string, expected:mixed, actual:mixed} */
    private function fail(string $message, mixed $expected, mixed $actual): array
    {
        return ['message' => $message, 'expected' => $expected, 'actual' => $actual];
    }
}
