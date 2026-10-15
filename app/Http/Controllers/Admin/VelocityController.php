<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Amount;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Velocity\Domain\VelocityRule;
use Modules\Velocity\Infrastructure\VelocityRuleRecord;

/** Risk → Velocity and limits: rules per portfolio, merchant, processor, BIN and card brand. Every change is audited. */
final class VelocityController extends AdminController
{
    public function index(Request $request): View
    {
        return view('admin.risk.velocity', [
            'rules' => VelocityRuleRecord::query()->orderBy('scope')->orderBy('name')->get(),
            'merchants' => Merchant::query()->orderBy('legal_name')->get()->mapWithKeys(fn ($m) => [$m->id => $m->displayName()]),
            'connectors' => array_keys(array_filter((array) config('connectors', []), fn ($c) => is_array($c) && isset($c['operations']))),
            'canEdit' => $this->can($request, 'merchants.limits.edit'),
        ]);
    }

    public function store(Request $request, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'merchants.limits.edit');
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', 'in:'.implode(',', VelocityRule::SCOPES)],
            'scope_value' => ['nullable', 'string', 'max:60'],
            'dimension' => ['required', 'in:'.implode(',', VelocityRule::DIMENSIONS)],
            'metric' => ['required', 'in:'.implode(',', VelocityRule::METRICS)],
            'window_minutes' => ['required', 'integer', 'min:1', 'max:44640'],
            'limit' => ['required', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3'],
            'environment' => ['required', 'in:live,sandbox,any'],
            'action' => ['required', 'in:block,monitor'],
        ]);
        $value = trim((string) ($v['scope_value'] ?? ''));
        if ($v['scope'] !== 'portfolio' && $value === '') {
            return back()->withInput()->with('error', 'Choose what this rule applies to (merchant, processor, BIN or card brand).');
        }
        if ($v['scope'] === 'bin' && preg_match('/^\d{6,8}$/', $value) !== 1) {
            return back()->withInput()->with('error', 'A BIN is 6 to 8 digits (never a full card number).');
        }
        $isMoney = in_array($v['metric'], ['amount', 'single_amount'], true);
        $limit = $isMoney ? Amount::toMinor($v['limit']) : (ctype_digit($v['limit']) ? (int) $v['limit'] : null);
        if ($limit === null || $limit < 1) {
            return back()->withInput()->with('error', $isMoney ? 'Enter the amount limit like 2500.00.' : 'Enter the count limit as a whole number.');
        }
        if ($isMoney && empty($v['currency'])) {
            return back()->withInput()->with('error', 'Amount limits need a currency.');
        }
        $r = VelocityRuleRecord::query()->create([
            'id' => Ids::new('vel'), 'name' => $v['name'], 'scope' => $v['scope'], 'scope_value' => match ($v['scope']) { 'portfolio' => null, 'card_brand' => mb_strtolower($value), default => $value },
            'dimension' => $v['dimension'], 'metric' => $v['metric'], 'window_minutes' => (int) $v['window_minutes'], 'limit_value' => $limit,
            'currency' => $isMoney ? strtoupper((string) $v['currency']) : null, 'environment' => $v['environment'], 'action' => $v['action'],
            'status' => 'active', 'created_by' => $this->userId(),
        ]);
        $audit->record('velocity_rule.created', 'velocity_rule', $r->id, null, $r->toArray(), $this->actor());

        return back()->with('status', 'Rule added. It applies to new payments right away.');
    }

    public function toggle(Request $request, string $rule, AuditTrail $audit): RedirectResponse
    {
        $this->requirePermission($request, 'merchants.limits.edit');
        $r = VelocityRuleRecord::query()->whereKey($rule)->firstOrFail();
        $before = $r->status;
        $r->forceFill(['status' => $r->status === 'active' ? 'paused' : 'active'])->save();
        $audit->record('velocity_rule.'.$r->status, 'velocity_rule', $r->id, ['status' => $before], ['status' => $r->status], $this->actor());

        return back()->with('status', 'Rule '.$r->status.'.');
    }
}
