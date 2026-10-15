<?php

declare(strict_types=1);

it('serves the terms, privacy and security pages publicly', function (string $path, string $heading) {
    $response = $this->get($path);

    $response->assertOk()
        ->assertSee('id="legal-content"', false)
        ->assertSee("<h1>$heading</h1>", false)
        ->assertSee('css/legal.css', false)
        ->assertHeader('Content-Security-Policy');

    // The CSP allows only nonce'd or same-origin styles: the pages must not rely on inline styles.
    expect($response->getContent())->not->toContain('<style')->not->toContain(' style="');
})->with([
    ['/terms', 'Terms of Service'],
    ['/privacy', 'Privacy Policy'],
    ['/security', 'Security'],
]);

it('links every portal footer and sign-in page to the legal pages as popups', function (string $path) {
    $html = (string) $this->get($path)->assertOk()->getContent();

    foreach (['/terms', '/privacy', '/security'] as $page) {
        expect($html)->toMatch('#href="'.preg_quote($page, '#').'" data-legal-popup#');
    }
    expect($html)->toContain('js/legal-popup.js?v=')
        // Versioned asset URLs: the web server caches static files for years, so each release needs a new URL.
        ->toContain('css/amplirail.css?v='.config('amplirail.version'));
})->with(['/', '/admin/login', '/app/login', '/terms']);
