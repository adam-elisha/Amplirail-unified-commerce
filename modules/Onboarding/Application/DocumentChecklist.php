<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\Actor;
use App\Support\ApiException;
use App\Support\Crypto\FieldEncryption;
use App\Support\FeatureFlags;
use App\Support\Ids;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Events\Contracts\Outbox;
use Modules\Onboarding\Infrastructure\DocumentRequest;
use Modules\Onboarding\Infrastructure\DocumentRequirement;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\MerchantDocument;

/**
 * AR-169. Requirements are configuration rows (document_requirements) plus dynamic requests (processor more-info).
 * Files are encrypted with the field key, stored privately under random names, never executed and only downloadable
 * through short-lived signed URLs that are permission checked and audited.
 */
final class DocumentChecklist
{
    private const CHECK_STAGES = ['submitted', 'needs_info'];

    public function __construct(
        private readonly MerchantLifecycle $lifecycle,
        private readonly FieldEncryption $crypto,
        private readonly AuditTrail $audit,
        private readonly Outbox $outbox,
        private readonly FeatureFlags $flags,
    ) {}

    /** @return list<array{document_type:string,label:string,stage:string,source:string,request_id:?string,satisfied:bool,document_id:?string}> */
    public function items(Merchant $merchant): array
    {
        $docs = MerchantDocument::query()->where('merchant_id', $merchant->id)->where('status', 'active')->orderByDesc('created_at')->get();
        $items = [];
        foreach ($this->applicableRequirements($merchant) as $req) {
            $doc = $docs->firstWhere('document_type', $req->document_type);
            $items[] = ['document_type' => $req->document_type, 'label' => $req->label, 'stage' => $req->stage, 'source' => 'requirement',
                'request_id' => null, 'satisfied' => $doc !== null, 'document_id' => $doc?->id];
        }
        foreach (DocumentRequest::query()->where('merchant_id', $merchant->id)->orderBy('created_at')->get() as $r) {
            $items[] = ['document_type' => $r->document_type, 'label' => $r->label, 'stage' => 'requested', 'source' => $r->source,
                'request_id' => $r->id, 'satisfied' => $r->status === 'satisfied', 'document_id' => $r->satisfied_by_document_id];
        }

        return $items;
    }

    /** @return array{complete:bool, outstanding:list<string>, enabled:bool} */
    public function summary(Merchant $merchant): array
    {
        $outstanding = [];
        foreach ($this->items($merchant) as $item) {
            if (! $item['satisfied'] && in_array($item['stage'], ['submitted', 'requested'], true)) {
                $outstanding[] = $item['label'];
            }
        }

        return ['complete' => $outstanding === [], 'outstanding' => $outstanding, 'enabled' => $this->flags->enabled('document_checklist')];
    }

    /** Re-evaluates completeness and emits documents.complete when the current stage's set is complete. */
    public function evaluate(Merchant $merchant, ?Actor $actor = null): bool
    {
        if (! $this->flags->enabled('document_checklist')) {
            return false;
        }
        $complete = $this->summary($merchant)['complete'];
        if ($complete && in_array($merchant->state, self::CHECK_STAGES, true)) {
            $this->lifecycle->apply($merchant, 'documents.complete', ['checklist' => 'complete'], null, null, $actor);
        }

        return $complete;
    }

    public function upload(Merchant $merchant, UploadedFile $file, string $documentType, Actor $actor): MerchantDocument
    {
        if (! preg_match('/^[a-z0-9_]{2,60}$/', $documentType)) {
            throw ApiException::invalid('invalid_document_type', 'Document type must be lower-case letters, digits or underscores.');
        }
        $maxBytes = (int) config('amplirail.documents.max_kilobytes') * 1024;
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > $maxBytes || $file->getSize() === 0) {
            throw ApiException::invalid('invalid_file', 'File is missing, empty or larger than '.((int) ($maxBytes / 1024 / 1024)).' MB.');
        }
        // Content sniffing: the declared type is ignored; the bytes decide.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, (array) config('amplirail.documents.allowed_mimes'), true)) {
            throw ApiException::invalid('invalid_file_type', 'Only PDF, JPEG and PNG files are accepted.');
        }
        $contents = (string) file_get_contents($file->getRealPath());
        $disk = (string) config('amplirail.documents.disk');
        $path = $merchant->tenant_id.'/'.bin2hex(random_bytes(16)).'.bin';   // never derived from the uploaded name
        Storage::disk($disk)->put($path, $this->crypto->encrypt(base64_encode($contents)));

        return DB::transaction(function () use ($merchant, $file, $documentType, $actor, $mime, $contents, $disk, $path): MerchantDocument {
            $doc = MerchantDocument::query()->create([
                'id' => Ids::new('doc'),
                'merchant_id' => $merchant->id,
                'document_type' => $documentType,
                'original_name' => self::safeName($file->getClientOriginalName()),
                'mime' => $mime,
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'storage_disk' => $disk,
                'storage_path' => $path,
                'status' => 'active',
                'uploaded_by_type' => $actor->type,
                'uploaded_by' => $actor->id,
            ]);
            DocumentRequest::query()->where('merchant_id', $merchant->id)->where('document_type', $documentType)->where('status', 'open')
                ->update(['status' => 'satisfied', 'satisfied_by_document_id' => $doc->id, 'satisfied_at' => now(), 'updated_at' => now()]);
            $this->audit->record('document.uploaded', 'merchant', $merchant->id, null, ['document_id' => $doc->id, 'type' => $documentType], $actor);
            $this->evaluate($merchant->refresh(), $actor);

            return $doc;
        });
    }

    public function remove(MerchantDocument $doc, Actor $actor): void
    {
        DB::transaction(function () use ($doc, $actor): void {
            /** @var Merchant $merchant */
            $merchant = Merchant::query()->whereKey($doc->merchant_id)->firstOrFail();
            $wasComplete = $this->summary($merchant)['complete'];
            $doc->forceFill(['status' => 'removed', 'removed_at' => now()])->save();
            $this->audit->record('document.removed', 'merchant', $merchant->id, null, ['document_id' => $doc->id], $actor);
            if ($wasComplete && ! $this->summary($merchant)['complete']) {
                $this->outbox->record('documents.reopened', 'merchant', $merchant->id, ['merchant_id' => $merchant->id, 'reason' => 'document_removed'], $actor, 'platform', $merchant->org_path);
            }
        });
    }

    /** Processor (or risk/operations) asks for more information: adds a requirement and reopens the checklist. */
    public function requestMoreInfo(Merchant $merchant, string $documentType, string $label, string $reason, string $source, Actor $actor): DocumentRequest
    {
        return DB::transaction(function () use ($merchant, $documentType, $label, $reason, $source, $actor): DocumentRequest {
            $request = DocumentRequest::query()->create([
                'id' => Ids::new('dreq'), 'merchant_id' => $merchant->id, 'document_type' => $documentType, 'label' => $label,
                'reason' => $reason, 'source' => $source, 'status' => 'open',
            ]);
            $event = $merchant->state === 'boarding' ? 'processor.more_info_required' : 'merchant.needs_info';
            $this->lifecycle->apply($merchant, $event, ['request_id' => $request->id, 'document_type' => $documentType, 'source' => $source], $reason, null, $actor);

            return $request;
        });
    }

    public function downloadUrl(MerchantDocument $doc, string $portal): string
    {
        $route = $portal === 'admin' ? 'admin.documents.download' : 'merchant.documents.download';

        return URL::temporarySignedRoute($route, now()->addMinutes((int) config('amplirail.documents.download_ttl_minutes')), ['document' => $doc->id]);
    }

    public function read(MerchantDocument $doc, Actor $actor): string
    {
        $this->audit->record('document.downloaded', 'merchant', $doc->merchant_id, null, ['document_id' => $doc->id], $actor);
        $cipher = (string) Storage::disk($doc->storage_disk)->get($doc->storage_path);

        return (string) base64_decode($this->crypto->decrypt($cipher), true);
    }

    /** @return Collection<int, DocumentRequirement> */
    private function applicableRequirements(Merchant $merchant): Collection
    {
        return DocumentRequirement::query()->where('active', true)->where('effective_from', '<=', now())->orderBy('stage')->orderBy('document_type')->get()
            ->filter(fn (DocumentRequirement $r) => $this->applies($r->applies_to ?? [], $merchant))
            ->unique('document_type')->values();
    }

    /** @param array<string,mixed> $rules */
    private function applies(array $rules, Merchant $m): bool
    {
        if (isset($rules['mcc_in']) && ! in_array($m->mcc, (array) $rules['mcc_in'], true)) {
            return false;
        }
        if (isset($rules['mcc_not_in']) && in_array($m->mcc, (array) $rules['mcc_not_in'], true)) {
            return false;
        }
        if (isset($rules['route_in']) && ! in_array($m->processor_route, (array) $rules['route_in'], true)) {
            return false;
        }
        if (isset($rules['channel_any']) && array_intersect((array) $rules['channel_any'], $m->channels) === []) {
            return false;
        }
        if (isset($rules['min_monthly_volume_minor']) && $m->monthly_volume_minor < (int) $rules['min_monthly_volume_minor']) {
            return false;
        }
        if (isset($rules['recurring']) && (bool) $rules['recurring'] !== $m->recurring) {
            return false;
        }

        return ! (isset($rules['merchant_type_in']) && ! in_array($m->merchant_type, (array) $rules['merchant_type_in'], true));
    }

    /** Display-only name: strips paths and control characters (path traversal in names is never used for storage). */
    public static function safeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = (string) preg_replace('/[^A-Za-z0-9 ._\-]/', '_', $base);
        $clean = ltrim($clean, '.');

        return mb_substr($clean === '' ? 'document' : $clean, 0, 150);
    }
}
