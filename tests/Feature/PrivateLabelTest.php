<?php

declare(strict_types=1);

use App\Support\Branding\BrandRecord;
use Tests\TestCase;

// Absolute URLs throughout: the test client resolves a relative URL against the previous request's host.
const PARTNER = 'http://partner.example';
const PLATFORM = 'http://localhost';

beforeEach(function () {
    $this->seedPlatform();
    BrandRecord::query()->create([
        'id' => 'brd_test_partner', 'tenant_id' => TestCase::OTHER_TENANT, 'name' => 'PartnerPay', 'company' => 'Partner Payments LLC',
        'mark' => 'PP', 'tagline' => 'Payments by your partner.', 'hosts' => ['partner.example'], 'status' => 'active', 'created_by' => 'test',
    ]);
});

/** Signed in, or waiting for the second step: either way the password step accepted this user. */
function passedPasswordStep(string $userId): bool
{
    return session('auth.pending') === $userId || auth()->id() === $userId;
}

it('shows the partner brand on the partner host and the platform brand elsewhere', function () {
    $this->get(PARTNER.'/admin/login')->assertOk()
        ->assertSee('<title>Sign in · PartnerPay</title>', false)->assertSee('Payments by your partner.')->assertSee('Partner Payments LLC');
    $this->get(PLATFORM.'/admin/login')->assertOk()
        ->assertSee('<title>Sign in · AmpliRail</title>', false)->assertSee('AmpliPay Group Inc.')->assertDontSee('PartnerPay');
});

it('only lets a partner\'s own users sign in on the partner host', function () {
    [$platformAdmin] = $this->adminUser('owner');
    [$partnerAdmin] = $this->adminUser('owner', TestCase::OTHER_TENANT);

    $this->post(PARTNER.'/admin/login', ['email' => $platformAdmin->email, 'password' => 'Correct-Horse-9!'])
        ->assertSessionHas('error', 'Email or password is incorrect.');
    expect(passedPasswordStep($platformAdmin->id))->toBeFalse();
    $this->assertGuest();

    $this->post(PARTNER.'/admin/login', ['email' => $partnerAdmin->email, 'password' => 'Correct-Horse-9!'])->assertSessionMissing('error');
    expect(passedPasswordStep($partnerAdmin->id))->toBeTrue();
});

it('still lets platform users sign in on the platform host', function () {
    [$platformAdmin] = $this->adminUser('owner');

    $this->post(PLATFORM.'/admin/login', ['email' => $platformAdmin->email, 'password' => 'Correct-Horse-9!'])->assertSessionMissing('error');
    expect(passedPasswordStep($platformAdmin->id))->toBeTrue();
});
