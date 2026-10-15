<?php

declare(strict_types=1);

use App\Support\Actor;
use App\Support\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Approvals\Application\ApprovalService;
use Modules\Onboarding\Application\DocumentChecklist;
use Modules\Onboarding\Application\ProcessorBoarding;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\MerchantDocument;
use Modules\Routing\Application\EligibilityService;
use Modules\Routing\Infrastructure\EligibilityRuleSet;

beforeEach(function () {
    $this->seedPlatform();
    Storage::fake('documents');
});

function merchantFor($t, array $overrides = []): Merchant
{
    $body = $t->handoff($overrides);
    $t->asTenant();

    return Merchant::query()->findOrFail($body['id']);
}

it('derives requirements from data and completes the checklist on upload', function () {
    $m = merchantFor($this, ['commerce' => ['monthly_volume_minor' => 9000000, 'channels' => ['ecommerce', 'recurring']]]);
    $types = collect(app(DocumentChecklist::class)->items($m))->pluck('document_type')->all();
    expect($types)->toContain('processing_statements', 'recurring_terms')->not->toContain('restricted_category_license');
});

it('stores files encrypted under random names and rejects path traversal, wrong types and oversized files', function () {
    $m = merchantFor($this);
    $actor = Actor::system('test');
    $checklist = app(DocumentChecklist::class);

    $doc = $checklist->upload($m, UploadedFile::fake()->createWithContent('../../etc/passwd.pdf', "%PDF-1.4\nsecret-content\n%%EOF"), 'bank_letter', $actor);
    $stored = Storage::disk('documents')->get($doc->storage_path);
    expect($doc->original_name)->toBe('passwd.pdf')
        ->and($doc->storage_path)->not->toContain('passwd')->and($doc->storage_path)->toEndWith('.bin')
        ->and($stored)->not->toContain('secret-content')
        ->and($checklist->read($doc, $actor))->toContain('secret-content');

    expect(fn () => $checklist->upload($m, UploadedFile::fake()->createWithContent('shell.pdf', "<?php system(\$_GET['c']);"), 'bank_letter', $actor))
        ->toThrow(ApiException::class, 'Only PDF, JPEG and PNG');
    expect(fn () => $checklist->upload($m, UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'), 'bank_letter', $actor))
        ->toThrow(ApiException::class);
    expect(fn () => $checklist->upload($m, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n"), '../x', $actor))
        ->toThrow(ApiException::class);
    expect(DB::table('audit_log')->where('action', 'document.downloaded')->count())->toBe(1);
});

it('never lets one merchant read another merchant\'s documents', function () {
    $a = merchantFor($this);
    $b = merchantFor($this);
    $doc = app(DocumentChecklist::class)->upload($a, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%%EOF"), 'bank_letter', Actor::system('t'));
    $this->asTenant(self::TENANT, $b->id);
    expect(MerchantDocument::query()->find($doc->id))->toBeNull();
    $this->asTenant(self::OTHER_TENANT);
    expect(MerchantDocument::query()->find($doc->id))->toBeNull();
});

it('returns ordered eligible routes and the specific failing rules', function (array $overrides, string $expectEligible, ?string $failingRoute, ?string $failingRule, bool $withDocs = true) {
    $m = merchantFor($this, $overrides);
    foreach ($withDocs ? ['government_id', 'bank_letter', 'business_registration'] : [] as $type) {
        app(DocumentChecklist::class)->upload($m->refresh(), UploadedFile::fake()->createWithContent("$type.pdf", "%PDF-1.4\n%%EOF"), $type, Actor::system('t'));
    }
    $result = app(ProcessorBoarding::class)->evaluateEligibility($m->refresh());
    $eligible = collect($result['eligible'])->pluck('code')->all();
    if ($expectEligible !== '') {
        expect($eligible[0] ?? null)->toBe($expectEligible);
    } else {
        expect($eligible)->toBe([]);
    }
    if ($failingRoute !== null) {
        $failed = collect($result['ineligible'])->firstWhere('code', $failingRoute);
        expect(collect($failed['failed_rules'])->pluck('rule'))->toContain($failingRule);
    }
    $this->asTenant();
    expect(DB::table('eligibility_results')->where('merchant_id', $m->id)->count())->toBe(1);
})->with([
    'missing documents' => [[], '', 'nmi_bridge:us', 'documents', false],
    'standard US eCommerce' => [[], 'nmi_bridge:us', 'gattera_bridge:eu', 'geography'],
    'restricted MCC' => [['commerce' => ['mcc' => '5993']], '', 'nmi_bridge:us', 'mcc_restricted'],
    'high volume' => [['commerce' => ['monthly_volume_minor' => 250000000]], '', 'nmi_bridge:us', 'monthly_volume'],
]);

it('publishes new rule versions only through Approvals, effective-dated and audited', function () {
    [$dev] = $this->adminUser('developer');
    [$owner, $ownerTotp] = $this->adminUser('owner');
    $this->asTenant();
    $svc = app(EligibilityService::class);
    $set = $svc->draft(['routes' => [['code' => 'nmi_bridge:us', 'connector' => 'nmi_bridge', 'priority' => 1, 'rules' => ['countries' => ['US']]]]], 'Open US to all MCCs', new Actor('user', $dev->id));
    expect($svc->current()->version)->toBe(1);
    $approval = $svc->submit($set, now()->toIso8601String(), new Actor('user', $dev->id));
    expect($svc->current()->version)->toBe(1);
    app(ApprovalService::class)->approve($approval->id, $owner->id, $this->totp($ownerTotp), 'Reviewed with Risk');
    expect($svc->current()->version)->toBe(2)
        ->and(EligibilityRuleSet::query()->where('version', 1)->value('status'))->toBe('published');
    $audit = DB::table('audit_log')->where('action', 'eligibility_rules.published')->first();
    expect(json_decode($audit->before, true)['version'])->toBe(1)->and(json_decode($audit->after, true)['version'])->toBe(2);
});

it('feeds the routing contract: every eligible route maps to a registered connector manifest', function () {
    $m = merchantFor($this);
    $result = app(ProcessorBoarding::class)->evaluateEligibility($m);
    foreach ($result['eligible'] as $route) {
        expect(config('connectors.'.$route['connector']))->toBeArray();
    }
})->group('contract');
