<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\FeatureFlags;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Events\Infrastructure\OutboxEvent;
use Modules\Onboarding\Application\CommandCenter;
use Modules\Webhooks\Infrastructure\WebhookDelivery;

/** Developers and system: connector health, simulator scenarios, outbox lag, webhook deliveries, feature flags. */
final class DevelopersController extends AdminController
{
    public function index(Request $request, CommandCenter $center, FeatureFlags $flags): View
    {
        $oldest = OutboxEvent::query()->whereNull('published_at')->orderBy('id')->first();

        return view('admin.developers', [
            'health' => $center->health(),
            'flags' => $flags->all(),
            'canFlag' => $this->can($request, 'settings.security.manage'),
            'backlog' => OutboxEvent::query()->whereNull('published_at')->count(),
            'oldestUnpublished' => $oldest?->created_at,
            'deliveries' => WebhookDelivery::query()->orderByDesc('created_at')->limit(30)->get(),
        ]);
    }

    public function flag(Request $request, string $flag, FeatureFlags $flags): RedirectResponse
    {
        $d = $request->validate(['enabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $flags->set($flag, (bool) $d['enabled'], $this->actor(), (string) $d['reason']);

        return back()->with('status', "Flag $flag is now ".($d['enabled'] ? 'on' : 'off').' (audited).');
    }
}
