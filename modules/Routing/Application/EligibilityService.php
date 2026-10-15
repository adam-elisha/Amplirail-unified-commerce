<?php

declare(strict_types=1);

namespace Modules\Routing\Application;

use App\Support\Actor;
use App\Support\Ids;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Approvals\Contracts\ApprovalFailed;
use Modules\Approvals\Contracts\ApprovalHandler;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Approvals\Contracts\ApprovalView;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Routing\Contracts\Eligibility;
use Modules\Routing\Domain\EligibilityEngine;
use Modules\Routing\Infrastructure\EligibilityResult;
use Modules\Routing\Infrastructure\EligibilityRuleSet;

/**
 * AR-170. Rule sets are drafted, submitted to Approvals, and published by the approval handler (never a direct edit).
 * The effective rule set is the highest published version whose effective_from has passed.
 */
final class EligibilityService implements ApprovalHandler, Eligibility
{
    public function __construct(
        private readonly EligibilityEngine $engine,
        private readonly ApprovalRequests $approvals,
        private readonly AuditTrail $audit,
    ) {}

    public function current(): ?EligibilityRuleSet
    {
        return EligibilityRuleSet::query()->where('status', 'published')->where('effective_from', '<=', now())
            ->orderByDesc('version')->first();
    }

    public function evaluate(string $merchantId, array $profile): ?array
    {
        $set = $this->current();
        if ($set === null) {
            return null;
        }
        $outcome = $this->engine->evaluate($profile, $set->rules);
        EligibilityResult::query()->create([
            'merchant_id' => $merchantId,
            'rule_set_id' => $set->id,
            'rule_set_version' => $set->version,
            'eligible' => $outcome['eligible'],
            'ineligible' => $outcome['ineligible'],
            'evaluated_at' => now(),
        ]);

        return ['rule_set_version' => $set->version] + $outcome + ['evaluated_at' => now()->toIso8601String()];
    }

    public function latest(string $merchantId): ?array
    {
        $r = EligibilityResult::query()->where('merchant_id', $merchantId)->orderByDesc('id')->first();

        return $r === null ? null : [
            'rule_set_version' => $r->rule_set_version,
            'eligible' => $r->eligible,
            'ineligible' => $r->ineligible,
            'evaluated_at' => $r->evaluated_at->toIso8601String(),
        ];
    }

    public function route(string $code): ?array
    {
        foreach ($this->current()?->rules['routes'] ?? [] as $route) {
            if (($route['code'] ?? null) === $code) {
                return $route;
            }
        }

        return null;
    }

    /** @param array<mixed> $input untrusted JSON from the editor; normalized and validated here */
    public function draft(array $input, string $notes, Actor $actor): EligibilityRuleSet
    {
        $rules = $this->validate($input);
        $version = (int) EligibilityRuleSet::query()->max('version') + 1;
        $set = EligibilityRuleSet::query()->create([
            'id' => Ids::new('ers'), 'version' => $version, 'status' => 'draft', 'rules' => $rules, 'notes' => $notes, 'created_by' => $actor->id,
        ]);
        $this->audit->record('eligibility_rules.drafted', 'eligibility_rule_set', $set->id, null, ['version' => $version], $actor);

        return $set;
    }

    public function submit(EligibilityRuleSet $set, string $effectiveFrom, Actor $actor): ApprovalView
    {
        return DB::transaction(function () use ($set, $effectiveFrom, $actor): ApprovalView {
            if ($set->status !== 'draft') {
                throw new \DomainException('Only drafts can be submitted.');
            }
            $before = $this->current();
            $approval = $this->approvals->request('eligibility_publish', 'eligibility_rule_set', $set->id, [
                'version' => $set->version,
                'effective_from' => $effectiveFrom,
                'before' => $before?->rules,
                'after' => $set->rules,
            ], $actor->id, $set->notes);
            $set->forceFill(['status' => 'pending_approval', 'approval_id' => $approval->id])->save();

            return $approval;
        });
    }

    public function type(): string
    {
        return 'eligibility_publish';
    }

    public function execute(ApprovalView $approval, Actor $approver): array
    {
        /** @var EligibilityRuleSet|null $set */
        $set = EligibilityRuleSet::query()->whereKey($approval->subjectId)->lockForUpdate()->first();
        if ($set === null || $set->status !== 'pending_approval') {
            throw new ApprovalFailed('Rule set is not awaiting approval.');
        }
        $before = $this->current();
        $effective = max(now(), Carbon::parse((string) ($approval->payload['effective_from'] ?? 'now')));
        $set->forceFill(['status' => 'published', 'effective_from' => $effective, 'published_at' => now()])->save();
        $this->audit->record('eligibility_rules.published', 'eligibility_rule_set', $set->id,
            ['version' => $before?->version, 'rules' => $before?->rules], ['version' => $set->version, 'rules' => $set->rules, 'effective_from' => $effective->toIso8601String()], $approver);

        return ['version' => $set->version, 'effective_from' => $effective->toIso8601String()];
    }

    /**
     * @param  array<mixed>  $input
     * @return array{routes: list<array<string,mixed>>}
     */
    private function validate(array $input): array
    {
        if (! isset($input['routes']) || ! is_array($input['routes']) || $input['routes'] === []) {
            throw new \InvalidArgumentException('Rule set needs at least one route.');
        }
        $routes = [];
        foreach ($input['routes'] as $route) {
            if (! is_array($route) || ! isset($route['code'], $route['connector']) || ! is_string($route['code']) || ! is_string($route['connector'])) {
                throw new \InvalidArgumentException('Each route needs a string code and connector.');
            }
            if (isset($route['rules']) && ! is_array($route['rules'])) {
                throw new \InvalidArgumentException("Route {$route['code']}: rules must be an object.");
            }
            $normalized = [];
            foreach ($route as $k => $v) {
                $normalized[(string) $k] = $v;
            }
            $routes[] = $normalized;
        }

        return ['routes' => $routes];
    }
}
