<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Infrastructure\MerchantDocument;
use Modules\Tenancy\Contracts\TenantScope;

/**
 * Signed (5-minute) and permission-checked document downloads. The tenant scope (and merchant scope for merchant users)
 * applies to the lookup; every download is audited by DocumentChecklist::read().
 */
final class DocumentDownloadController
{
    public function admin(string $document, DocumentChecklist $checklist, TenantScope $tenants): Response
    {
        return $this->send($document, $checklist, $tenants);
    }

    public function merchant(string $document, DocumentChecklist $checklist, TenantScope $tenants): Response
    {
        return $this->send($document, $checklist, $tenants);
    }

    private function send(string $id, DocumentChecklist $checklist, TenantScope $tenants): Response
    {
        $doc = MerchantDocument::query()->whereKey($id)->where('status', 'active')->firstOrFail();
        $bytes = $checklist->read($doc, $tenants->current()->actor);

        return response($bytes, 200, [
            'Content-Type' => $doc->mime,
            'Content-Disposition' => 'attachment; filename="'.$doc->original_name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
