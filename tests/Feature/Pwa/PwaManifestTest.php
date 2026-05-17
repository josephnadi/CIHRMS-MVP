<?php

it('serves the web app manifest with the correct MIME type', function () {
    $path = public_path('manifest.webmanifest');
    expect(file_exists($path))->toBeTrue();

    $manifest = json_decode(file_get_contents($path), true);
    expect($manifest)->toBeArray();
    expect($manifest['name'])->toBe('CIHRMS Ghana');
    expect($manifest['short_name'])->toBe('CIHRMS');
    expect($manifest['theme_color'])->toBe('#0d1452');
    expect($manifest['display'])->toBe('standalone');
    expect($manifest['icons'])->toBeArray()->not->toBeEmpty();
});

it('declares all critical PWA shortcuts (clock-in, payslip, leave)', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
    $shortcuts = collect($manifest['shortcuts'] ?? []);

    expect($shortcuts->pluck('name')->all())->toContain('Clock in', 'Latest payslip', 'Request leave');
});

it('serves the service-worker script at /sw.js for root-scope registration', function () {
    $path = public_path('sw.js');
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)->toContain('cihrms-sync-punches');
    expect($contents)->toContain("self.addEventListener('install'");
    expect($contents)->toContain("self.addEventListener('fetch'");
    expect($contents)->toContain("self.addEventListener('sync'");
});

it('exposes an /offline fallback route that responds without authentication', function () {
    $response = $this->get('/offline');

    $response->assertStatus(200);
    $response->assertSee('Offline', false);
    $response->assertSee('Try again', false);
});

it('renders the manifest link + theme-color meta on every page', function () {
    $response = $this->get('/');

    $response->assertSee('rel="manifest" href="/manifest.webmanifest"', false);
    $response->assertSee('name="theme-color" content="#0d1452"', false);
    $response->assertSee('apple-mobile-web-app-capable', false);
});
