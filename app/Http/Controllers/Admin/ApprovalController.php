<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Approvals\Application\ApprovalService;
use Modules\Approvals\Infrastructure\Approval;

/** Dual-control queue. Permission, self-approval, step-up and note rules are enforced by ApprovalService, not here. */
final class ApprovalController extends AdminController
{
    public function index(): View
    {
        return view('admin.approvals', [
            'pending' => Approval::query()->where('status', 'pending')->orderBy('requested_at')->get(),
            'decided' => Approval::query()->where('status', '!=', 'pending')->orderByDesc('decided_at')->limit(25)->get(),
            'me' => $this->userId(),
        ]);
    }

    public function approve(Request $request, string $approval, ApprovalService $approvals): RedirectResponse
    {
        $data = $request->validate(['step_up_code' => ['required', 'string', 'max:8'], 'note' => ['required', 'string', 'min:5', 'max:1000']]);
        $result = $approvals->approve($approval, $this->userId(), (string) $data['step_up_code'], (string) $data['note']);

        return $result->status === 'approved'
            ? back()->with('status', 'Approved and executed.')
            : back()->with('error', 'Approved, but execution failed: '.(Approval::query()->find($approval)->result['error'] ?? 'see audit log'));
    }

    public function reject(Request $request, string $approval, ApprovalService $approvals): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);
        $approvals->reject($approval, $this->userId(), (string) $data['note']);

        return back()->with('status', 'Rejected.');
    }
}
