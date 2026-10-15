<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Branding\Brand;
use App\Support\Branding\BrandRecord;
use App\Support\Branding\BrandResolver;
use App\Support\Ids;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Identity\Application\UserAdministration;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Tenancy\Infrastructure\OrgNode;

/**
 * Private label partners (platform owners only). A partner is its own organization (tenant): its own staff,
 * merchants, MIDs, fee schedules and data, isolated by row-level security, shown under its own brand and host names.
 */
final class PartnerController extends AdminController
{
    private const TYPES = ['iso' => 'ISO or agent office', 'psp' => 'Payment service provider', 'bank' => 'Bank or acquirer', 'saas' => 'Software company', 'other' => 'Other'];

    public function index(TenantScope $tenants): View
    {
        $brands = BrandRecord::query()->get()->keyBy('tenant_id');
        $orgs = OrgNode::query()->where('type', 'organization')->orderBy('created_at')->get();

        return view('admin.partners.index', [
            'orgs' => $orgs,
            'brands' => $brands,
            'types' => self::TYPES,
            'me' => $tenants->current()->tenantId,
            'platform' => Brand::platform(),
        ]);
    }

    public function store(Request $request, UserAdministration $users, AuditTrail $audit): RedirectResponse
    {
        $v = $request->validate($this->rules() + [
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:190'],
        ]);
        $hosts = $this->hosts((string) ($v['hosts'] ?? ''));
        if (($clash = $this->hostClash($hosts, null)) !== null) {
            return back()->withInput()->with('error', "The address $clash is already used by another brand.");
        }
        $logo = $this->logo($request->file('logo'));
        if ($logo === false) {
            return back()->withInput()->with('error', 'The logo must be a PNG, JPEG or SVG of at most 200 KB.');
        }

        $orgId = Ids::new('org');
        DB::transaction(function () use ($v, $hosts, $logo, $orgId, $users, $audit): void {
            OrgNode::query()->create([
                'id' => $orgId, 'type' => 'organization', 'parent_id' => null, 'tenant_id' => $orgId,
                'path' => '/'.$orgId.'/', 'name' => $v['company'], 'status' => 'active',
            ]);
            BrandRecord::query()->create($this->brandData($v, $hosts, $logo) + [
                'id' => Ids::new('brd'), 'tenant_id' => $orgId, 'created_by' => $this->userId(), 'status' => 'active',
            ]);
            $users->invite($orgId, $orgId, '/'.$orgId.'/', null, (string) $v['owner_email'], (string) $v['owner_name'], 'owner');
            $audit->record('partner.created', 'organization', $orgId, null, ['name' => $v['name'], 'type' => $v['partner_type'], 'hosts' => $hosts], $this->actor());
        });
        BrandResolver::forget($hosts);

        return back()->with('status', "Partner {$v['name']} created. The owner was invited by email. Point the partner's addresses to this server in DNS and CWP (with SSL) to turn on their branded sign-in.");
    }

    public function update(Request $request, string $org, AuditTrail $audit): RedirectResponse
    {
        $node = OrgNode::query()->whereKey($org)->where('type', 'organization')->firstOrFail();
        $v = $request->validate($this->rules());
        $hosts = $this->hosts((string) ($v['hosts'] ?? ''));
        if (($clash = $this->hostClash($hosts, $node->id)) !== null) {
            return back()->with('error', "The address $clash is already used by another brand.");
        }
        $logo = $this->logo($request->file('logo'));
        if ($logo === false) {
            return back()->with('error', 'The logo must be a PNG, JPEG or SVG of at most 200 KB.');
        }
        $existing = BrandRecord::query()->where('tenant_id', $node->id)->first();
        $old = $existing?->hosts ?? [];
        $data = $this->brandData($v, $hosts, $logo ?? $existing?->logo_data);
        if ($request->boolean('remove_logo')) {
            $data['logo_data'] = null;
        }
        if ($existing === null) {
            BrandRecord::query()->create($data + ['id' => Ids::new('brd'), 'tenant_id' => $node->id, 'created_by' => $this->userId(), 'status' => 'active']);
        } else {
            $existing->forceFill($data)->save();
        }
        BrandResolver::forget(array_merge($old, $hosts));
        $audit->record('partner.brand_updated', 'organization', $node->id, null, ['hosts' => $hosts], $this->actor());

        return back()->with('status', 'Brand saved.');
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'company' => ['required', 'string', 'max:120'],
            'partner_type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'mark' => ['required', 'string', 'max:3'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'navy_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['nullable', 'email', 'max:120'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'terms_url' => ['nullable', 'url', 'max:300'],
            'privacy_url' => ['nullable', 'url', 'max:300'],
            'security_url' => ['nullable', 'url', 'max:300'],
            'hosts' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'file', 'max:200'],
        ];
    }

    /**
     * @param  array<string,mixed>  $v
     * @param  list<string>  $hosts
     * @return array<string,mixed>
     */
    private function brandData(array $v, array $hosts, ?string $logo): array
    {
        return [
            'partner_type' => $v['partner_type'],
            'name' => trim((string) $v['name']),
            'company' => trim((string) $v['company']),
            'mark' => mb_strtoupper(trim((string) $v['mark'])),
            'tagline' => ($v['tagline'] ?? null) ?: null,
            'primary_color' => strtoupper((string) $v['primary_color']),
            'navy_color' => strtoupper((string) $v['navy_color']),
            'logo_data' => $logo,
            'support_email' => ($v['support_email'] ?? null) ?: null,
            'support_phone' => ($v['support_phone'] ?? null) ?: null,
            'terms_url' => ($v['terms_url'] ?? null) ?: null,
            'privacy_url' => ($v['privacy_url'] ?? null) ?: null,
            'security_url' => ($v['security_url'] ?? null) ?: null,
            'hosts' => $hosts,
        ];
    }

    /** @return list<string> */
    private function hosts(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $h) {
            $n = BrandResolver::normalizeHost($h);
            if ($n !== '' && ! in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return array_slice($out, 0, 10);
    }

    /** @param list<string> $hosts */
    private function hostClash(array $hosts, ?string $ownTenant): ?string
    {
        foreach ($hosts as $h) {
            $q = BrandRecord::query()->whereJsonContains('hosts', $h);
            if ($ownTenant !== null) {
                $q->where('tenant_id', '!=', $ownTenant);
            }
            if ($q->exists()) {
                return $h;
            }
        }

        return null;
    }

    /** @return string|false|null data: URI, false when invalid, null when no file */
    private function logo(?UploadedFile $file): string|false|null
    {
        if ($file === null) {
            return null;
        }
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        if ($bytes === '' || strlen($bytes) > 200 * 1024) {
            return false;
        }
        $mime = match (true) {
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_contains(substr($bytes, 0, 512), '<svg') => 'image/svg+xml',
            default => '',
        };

        return $mime === '' ? false : 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
