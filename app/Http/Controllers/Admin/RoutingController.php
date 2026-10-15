<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Routing\Application\EligibilityService;
use Modules\Routing\Infrastructure\EligibilityRuleSet;

/** Eligibility rule sets (AR-170): versioned drafts, submitted to Approvals; publishing is never a direct edit. */
final class RoutingController extends AdminController
{
    public function index(EligibilityService $eligibility): View
    {
        $current = $eligibility->current();

        return view('admin.routing', [
            'current' => $current,
            'sets' => EligibilityRuleSet::query()->orderByDesc('version')->get(),
            'json' => json_encode($current->rules ?? ['routes' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'connectors' => config('connectors'),
        ]);
    }

    public function draft(Request $request, EligibilityService $eligibility): RedirectResponse
    {
        $d = $request->validate(['rules' => ['required', 'json'], 'notes' => ['required', 'string', 'min:5', 'max:1000']]);
        $set = $eligibility->draft((array) json_decode((string) $d['rules'], true), (string) $d['notes'], $this->actor());

        return back()->with('status', "Draft version {$set->version} saved.");
    }

    public function submit(Request $request, string $ruleSet, EligibilityService $eligibility): RedirectResponse
    {
        $d = $request->validate(['effective_from' => ['required', 'date']]);
        $eligibility->submit(EligibilityRuleSet::query()->whereKey($ruleSet)->firstOrFail(), (string) $d['effective_from'], $this->actor());

        return back()->with('status', 'Submitted to Approvals (routing.publish).');
    }
}
