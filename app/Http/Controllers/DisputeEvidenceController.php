<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Disputes\Infrastructure\Dispute;
use Modules\Disputes\Infrastructure\DisputeDocument;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * Evidence files for a dispute (receipts, delivery proof, messages, signed terms). Stored with the same encryption,
 * content checks and signed 5-minute downloads as onboarding documents. Works for both portals: the tenant scope
 * limits merchant users to their own disputes.
 */
final class DisputeEvidenceController
{
    public function store(Request $request, string $dispute, DocumentChecklist $documents, TenantScope $tenants): RedirectResponse
    {
        $v = $request->validate(['file' => ['required', 'file', 'max:10240'], 'label' => ['required', 'string', 'max:120']]);
        $d = Dispute::query()->whereKey($dispute)->firstOrFail();
        if (! $d->isOpen()) {
            return back()->with('error', 'This dispute is closed; evidence can no longer be added.');
        }
        $merchant = Merchant::query()->whereKey($d->merchant_id)->firstOrFail();
        $actor = $tenants->current()->actor;
        $doc = $documents->upload($merchant, $request->file('file'), 'dispute_evidence', $actor);
        DisputeDocument::query()->create([
            'id' => Ids::new('dsd'), 'dispute_id' => $d->id, 'merchant_id' => $d->merchant_id, 'document_id' => $doc->id,
            'label' => trim($v['label']), 'uploaded_by' => $actor->id,
        ]);

        return back()->with('status', 'Evidence uploaded.');
    }
}
