<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use Connectors\ConnectorRegistry;
use Illuminate\Support\Carbon;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Onboarding\Infrastructure\KybCheck;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\StateTransition;
use Modules\Payments\Contracts\PaymentReports;
use Modules\Webhooks\Contracts\WebhookStatus;

/**
 * AR-173 activation SLA command center. Read-only: every figure is computed from merchants, transitions, attempts and
 * webhook deliveries; nothing here changes state. Sample-data mode returns a fixed fixture for demos.
 */
final class CommandCenter
{
    public function __construct(
        private readonly DocumentChecklist $checklist,
        private readonly ApprovalRequests $approvals,
        private readonly PaymentReports $payments,
        private readonly WebhookStatus $webhooks,
        private readonly ConnectorRegistry $connectors,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(?Carbon $now = null): array
    {
        $now ??= now();

        return [
            'generated_at' => $now->toIso8601String(),
            'sample_data' => false,
            'pipeline' => $this->pipeline($now),
            'merchants' => $this->rows($now),
            'time_in_state' => $this->timeInState($now),
            'health' => $this->health(),
        ];
    }

    /** @return list<array{bucket:string, count:int, change:int}> */
    public function pipeline(Carbon $now): array
    {
        $current = Merchant::query()->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
        $yesterday = $this->statesAt($now->copy()->subDay());
        $out = [];
        foreach ((array) config('amplirail.onboarding.pipeline') as $bucket => $states) {
            $count = 0;
            $before = 0;
            foreach ((array) $states as $s) {
                $count += (int) ($current[$s] ?? 0);
                $before += $yesterday[$s] ?? 0;
            }
            $out[] = ['bucket' => (string) $bucket, 'count' => $count, 'change' => $count - $before];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function rows(Carbon $now): array
    {
        $sla = (array) config('amplirail.onboarding.sla');
        $fraction = (float) config('amplirail.onboarding.at_risk_fraction');
        $rows = [];
        $merchants = Merchant::query()->whereNotIn('state', ['active', 'closed', 'declined'])->orderBy('state_changed_at')->get();
        foreach ($merchants as $m) {
            $rule = $sla[$m->state] ?? ['owner' => 'Operations', 'next_action' => 'Review', 'hours' => 24];
            $deadline = $m->state_changed_at->copy()->addHours((int) $rule['hours']);
            $elapsed = $m->state_changed_at->diffInMinutes($now) / 60;
            $rows[] = [
                'merchant_id' => $m->id,
                'public_id' => $m->public_id,
                'name' => $m->displayName(),
                'state' => $m->state,
                'days_in_state' => round($m->state_changed_at->diffInMinutes($now) / 1440, 1),
                'blocker' => $this->blocker($m),
                'owner' => $rule['owner'],
                'next_action' => $rule['next_action'],
                'deadline' => $deadline->toIso8601String(),
                'overdue' => $now->greaterThan($deadline),
                'at_risk' => $elapsed >= $fraction * (int) $rule['hours'],
            ];
        }
        usort($rows, fn ($a, $b) => [! $a['overdue'], ! $a['at_risk'], $a['deadline']] <=> [! $b['overdue'], ! $b['at_risk'], $b['deadline']]);

        return $rows;
    }

    /** @return array{per_state: list<array{state:string, median_hours:float, average_hours:float, samples:int}>, time_to_live: array{median_hours:?float, average_hours:?float, samples:int}} */
    public function timeInState(Carbon $now): array
    {
        $since = $now->copy()->subDays(90);
        $transitions = StateTransition::query()->where('created_at', '>=', $since->copy()->subDays(30))->orderBy('merchant_id')->orderBy('id')->get()->groupBy('merchant_id');
        $durations = [];
        $toLive = [];
        foreach ($transitions as $list) {
            $entered = null;
            $state = null;
            $created = null;
            foreach ($list as $t) {
                if ($t->event === 'application.created') {
                    $created = $t->created_at;
                }
                if ($state !== null && $entered !== null && $t->created_at->greaterThanOrEqualTo($since)) {
                    $durations[$state][] = $entered->diffInMinutes($t->created_at) / 60;
                }
                $state = $t->to_state;
                $entered = $t->created_at;
                if ($t->event === 'merchant.live' && $created !== null && $t->created_at->greaterThanOrEqualTo($since)) {
                    $toLive[] = $created->diffInMinutes($t->created_at) / 60;
                }
            }
        }
        $perState = [];
        foreach ($durations as $s => $values) {
            $perState[] = ['state' => (string) $s, 'median_hours' => round(self::median($values), 1), 'average_hours' => round(array_sum($values) / count($values), 1), 'samples' => count($values)];
        }

        return [
            'per_state' => $perState,
            'time_to_live' => [
                'median_hours' => $toLive === [] ? null : round(self::median($toLive), 1),
                'average_hours' => $toLive === [] ? null : round(array_sum($toLive) / count($toLive), 1),
                'samples' => count($toLive),
            ],
        ];
    }

    /** @return array{connectors: list<array<string,mixed>>, webhooks: array<string,mixed>} */
    public function health(): array
    {
        $recent = collect($this->payments->connectorHealth(60))->keyBy('connector');
        $connectors = [];
        foreach ($this->connectors->manifests() as $code => $m) {
            $r = $recent->get($code);
            $connectors[] = [
                'connector' => $code,
                'name' => $m['name'] ?? $code,
                'enabled' => (bool) ($m['enabled'] ?? false),
                'attempts_1h' => $r['attempts'] ?? 0,
                'error_rate' => $r['error_rate'] ?? null,
                'avg_latency_ms' => $r['avg_latency_ms'] ?? null,
                'status' => ! ($m['enabled'] ?? false) ? 'not_connected' : (($r['error_rate'] ?? 0) > 5 ? 'degraded' : 'healthy'),
            ];
        }

        return ['connectors' => $connectors, 'webhooks' => $this->webhooks->stats24h()];
    }

    /**
     * Fixed fixture for demos (clearly labelled "Sample data" in the UI).
     *
     * @return array<string,mixed>
     */
    public static function sample(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'sample_data' => true,
            'pipeline' => [
                ['bucket' => 'New', 'count' => 8, 'change' => 2], ['bucket' => 'KYB/KYC', 'count' => 5, 'change' => -1],
                ['bucket' => 'Risk', 'count' => 3, 'change' => 0], ['bucket' => 'Processor', 'count' => 7, 'change' => 1],
                ['bucket' => 'Test', 'count' => 4, 'change' => 1], ['bucket' => 'Live', 'count' => 142, 'change' => 3],
            ],
            'merchants' => [
                ['merchant_id' => 'mer_sample1', 'public_id' => 'EDP-M-00012871', 'name' => '[Sample] Landmark Home Decor', 'state' => 'needs_info', 'days_in_state' => 2.0,
                    'blocker' => 'Ownership evidence requested', 'owner' => 'Operations', 'next_action' => 'Obtain legal ownership documentation',
                    'deadline' => now()->subHours(4)->toIso8601String(), 'overdue' => true, 'at_risk' => true],
                ['merchant_id' => 'mer_sample2', 'public_id' => 'EDP-M-00012902', 'name' => '[Sample] Moes Limo', 'state' => 'manual_review', 'days_in_state' => 1.0,
                    'blocker' => 'Enhanced due diligence', 'owner' => 'Risk', 'next_action' => 'Validate bank statement and application signals',
                    'deadline' => now()->addHours(3)->toIso8601String(), 'overdue' => false, 'at_risk' => true],
            ],
            'time_in_state' => ['per_state' => [
                ['state' => 'submitted', 'median_hours' => 6.5, 'average_hours' => 9.1, 'samples' => 40],
                ['state' => 'underwriting', 'median_hours' => 11.0, 'average_hours' => 14.2, 'samples' => 38],
                ['state' => 'boarding', 'median_hours' => 20.5, 'average_hours' => 26.0, 'samples' => 35],
            ], 'time_to_live' => ['median_hours' => 86.0, 'average_hours' => 97.5, 'samples' => 31]],
            'health' => ['connectors' => [
                ['connector' => 'simulator', 'name' => 'AmpliRail SIM (test)', 'enabled' => true, 'attempts_1h' => 412, 'error_rate' => 0.2, 'avg_latency_ms' => 38, 'status' => 'healthy'],
                ['connector' => 'nmi_bridge', 'name' => 'Card bridge US', 'enabled' => true, 'attempts_1h' => 1210, 'error_rate' => 0.4, 'avg_latency_ms' => 212, 'status' => 'healthy'],
            ], 'webhooks' => ['delivered' => 1988, 'failed' => 12, 'pending' => 3, 'success_rate' => 99.4]],
        ];
    }

    private function blocker(Merchant $m): string
    {
        switch ($m->state) {
            case 'submitted':
            case 'needs_info':
                $out = $this->checklist->summary($m)['outstanding'];

                return $out === [] ? 'Waiting for checklist evaluation' : 'Missing: '.implode(', ', array_slice($out, 0, 3));
            case 'kyb_pending':
                return 'KYB checks running';
            case 'manual_review':
                $kyb = KybCheck::query()->where('merchant_id', $m->id)->orderByDesc('created_at')->first();

                return $kyb !== null && $kyb->reasons !== [] ? 'KYB: '.implode('; ', $kyb->reasons) : 'Manual risk review';
            case 'underwriting':
                return 'Awaiting underwriting decision';
            case 'approved':
                return 'Not yet submitted to a processor';
            case 'boarding':
                return match (true) {
                    $m->processor_approved_at === null => 'Awaiting processor approval',
                    $m->mid_created_at === null => 'Production MID not recorded',
                    default => 'Credentials not recorded',
                };
            case 'test_mode':
                return 'Validation not passed';
            case 'validation':
                return $this->approvals->pendingFor('go_live', $m->id) !== null ? 'Awaiting second approval' : 'Go-live not requested';
            case 'suspended':
                return 'Suspended';
        }

        return '';
    }

    /** @return array<string,int> state => count as of $at */
    private function statesAt(Carbon $at): array
    {
        $last = StateTransition::query()->where('created_at', '<=', $at)->orderBy('id')->get()->groupBy('merchant_id')
            ->map(fn ($list) => $list->last()?->to_state);
        $out = [];
        foreach ($last as $state) {
            if (is_string($state)) {
                $out[$state] = ($out[$state] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
