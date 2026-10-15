<?php

declare(strict_types=1);

/*
 * AGENTS.md rule 9 / master document 4.2: a module never imports another module's internals; it uses Contracts or events.
 * Rules 1 and 10: provider code stays in connectors/, and the connector layer never depends on modules.
 */

$modules = array_map('basename', glob(__DIR__.'/../../modules/*', GLOB_ONLYDIR) ?: []);

foreach ($modules as $module) {
    foreach ($modules as $other) {
        if ($other === $module) {
            continue;
        }
        arch("$module does not use $other internals")
            ->expect("Modules\\$module")
            ->not->toUse(["Modules\\$other\\Domain", "Modules\\$other\\Application", "Modules\\$other\\Infrastructure", "Modules\\$other\\Jobs", "Modules\\$other\\Mail"])
            ->group('architecture');
    }
}

arch('connectors never depend on modules or the app layer')
    ->expect('Connectors')->not->toUse(['Modules', 'App\Http'])->group('architecture');

arch('domain code is framework free')
    ->expect(['Modules\Onboarding\Domain', 'Modules\Routing\Domain', 'Modules\Payments\Domain', 'Modules\Identity\Domain'])
    ->not->toUse(['Illuminate'])->group('architecture');

arch('every source file declares strict types')
    ->expect(['App', 'Modules', 'Connectors'])->toUseStrictTypes()->group('architecture');

arch('no debugging leftovers')
    ->expect(['App', 'Modules', 'Connectors'])->not->toUse(['dd', 'dump', 'var_dump', 'print_r', 'ray'])->group('architecture');

arch('the core package stays untouched by the app (it is a dependency, not app code)')
    ->expect('AmpliRail')->not->toUse(['Illuminate', 'Modules', 'App'])->group('architecture');

it('never uses the forbidden word "vault" or other providers\' object names in app code', function () {
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../modules'));
    foreach (array_merge(iterator_to_array($it), iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app'))),
        iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../resources/views')))) as $file) {
        if ($file->isFile() && preg_match('/\.php$/', $file->getFilename())) {
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bvault\b|PaymentIntent|\bsk_live|\bpk_live|whsec_/i', $src)) {
                $hits[] = $file->getPathname();
            }
        }
    }
    expect($hits)->toBe([]);
})->group('architecture');
