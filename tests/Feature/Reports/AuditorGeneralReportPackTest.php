<?php

use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Reports\AuditorGeneralReportPack;

beforeEach(function () {
    $this->pack = app(AuditorGeneralReportPack::class);
});

it('generates a zip with the expected top-level files', function () {
    User::factory()->create(); // ensure user exists for any related lookups
    $path = $this->pack->generate(now()->year);

    expect(file_exists($path))->toBeTrue();

    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();

    // Pull the names of every file in the archive
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();

    // Manifest is required
    expect($names)->toContain('MANIFEST.md');

    // Audit chain verification report is required (key AG signal)
    expect(collect($names)->contains(fn ($n) => str_starts_with($n, 'audit/chain_verification')))->toBeTrue();

    // Subfolders should exist even if empty (we create them, then populate)
    expect(collect($names)->contains(fn ($n) => str_starts_with($n, 'payroll/runs')))->toBeTrue();
    expect(collect($names)->contains(fn ($n) => str_starts_with($n, 'identity/verifications')))->toBeTrue();
    expect(collect($names)->contains(fn ($n) => str_starts_with($n, 'loans/accounts')))->toBeTrue();
    expect(collect($names)->contains(fn ($n) => str_starts_with($n, 'whistleblower/summary')))->toBeTrue();
});

it('writes a SHA-256 manifest entry for every file in the pack', function () {
    User::factory()->create();
    $path = $this->pack->generate(now()->year);

    $zip = new ZipArchive();
    $zip->open($path);
    $manifest = $zip->getFromName('MANIFEST.md');
    $zip->close();

    expect($manifest)->toBeString();
    expect($manifest)->toContain('# Auditor-General Report Pack');
    expect($manifest)->toContain('## Files');
    expect($manifest)->toContain('SHA-256');
    // Each SHA-256 in the manifest table is a 64-hex string in backticks
    expect(preg_match_all('/`[0-9a-f]{64}`/', $manifest))->toBeGreaterThan(3);
});

it('does NOT include any whistleblower case content — only summary counts', function () {
    User::factory()->create();
    $path = $this->pack->generate(now()->year);

    $zip = new ZipArchive();
    $zip->open($path);

    $summary = $zip->getFromName('whistleblower/summary.csv');
    expect($summary)->toBeString();
    // Header line + zero+ data rows. Critically, no `description`/`body` column ever.
    expect($summary)->toContain('Category');
    expect($summary)->not->toContain('description');
    expect($summary)->not->toContain('body');

    // No other whistleblower files should exist in the pack
    $whistleblowerFiles = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with($zip->getNameIndex($i), 'whistleblower/')) $whistleblowerFiles++;
    }
    expect($whistleblowerFiles)->toBe(1);

    $zip->close();
});
