<?php

declare(strict_types=1);

use Modules\Events\Infrastructure\OutboxEvent;

beforeEach(function () {
    $this->seedPlatform();
});

it('keeps relaying across passes when run as a long-lived process', function () {
    // The command resets scoped services between passes; the second pass used to fail with MissingTenantContext.
    $this->handoff();

    $this->artisan('amplirail:outbox:relay', ['--max-seconds' => 1, '--sleep' => 50])->assertSuccessful();

    $this->asTenant();
    expect(OutboxEvent::query()->count())->toBeGreaterThan(0)
        ->and(OutboxEvent::query()->whereNull('published_at')->count())->toBe(0);
});
