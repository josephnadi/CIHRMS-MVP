<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Fake both disks so we can arrange + assert without touching real storage.
    Storage::fake('public');
    Storage::fake('local');
});

it('moves files from public/{avatars,employee-documents} to local with same relative paths', function () {
    Storage::disk('public')->put('avatars/alice.jpg', 'AVATAR_BYTES_ALICE');
    Storage::disk('public')->put('avatars/sub/bob.png', 'AVATAR_BYTES_BOB');
    Storage::disk('public')->put('employee-documents/cv-charlie.pdf', 'CV_BYTES_CHARLIE');

    $this->artisan('storage:depublic-employee-files')
        ->expectsOutputToContain('moved=3')
        ->assertSuccessful();

    // Sources gone
    expect(Storage::disk('public')->exists('avatars/alice.jpg'))->toBeFalse();
    expect(Storage::disk('public')->exists('avatars/sub/bob.png'))->toBeFalse();
    expect(Storage::disk('public')->exists('employee-documents/cv-charlie.pdf'))->toBeFalse();

    // Destinations present at the same relative paths
    expect(Storage::disk('local')->get('avatars/alice.jpg'))->toBe('AVATAR_BYTES_ALICE');
    expect(Storage::disk('local')->get('avatars/sub/bob.png'))->toBe('AVATAR_BYTES_BOB');
    expect(Storage::disk('local')->get('employee-documents/cv-charlie.pdf'))->toBe('CV_BYTES_CHARLIE');
});

it('dry-run reports moves without writing or deleting', function () {
    Storage::disk('public')->put('avatars/alice.jpg', 'AVATAR_BYTES');

    $this->artisan('storage:depublic-employee-files', ['--dry-run' => true])
        ->expectsOutputToContain('MOVE  avatars/alice.jpg')
        ->expectsOutputToContain('Dry-run')
        ->assertSuccessful();

    // Nothing actually moved
    expect(Storage::disk('public')->exists('avatars/alice.jpg'))->toBeTrue();
    expect(Storage::disk('local')->exists('avatars/alice.jpg'))->toBeFalse();
});

it('is idempotent — re-running skips files already at the destination', function () {
    Storage::disk('public')->put('avatars/x.jpg', 'PUBLIC_BYTES');
    Storage::disk('local')->put('avatars/x.jpg',  'LOCAL_PREEXISTING');

    $this->artisan('storage:depublic-employee-files')
        ->expectsOutputToContain('skipped=1')
        ->assertSuccessful();

    // Source still there (we didn't delete it because we didn't move it)
    expect(Storage::disk('public')->get('avatars/x.jpg'))->toBe('PUBLIC_BYTES');
    // Destination untouched
    expect(Storage::disk('local')->get('avatars/x.jpg'))->toBe('LOCAL_PREEXISTING');
});

it('honours --include to restrict scope', function () {
    Storage::disk('public')->put('avatars/alice.jpg', 'AVATAR');
    Storage::disk('public')->put('employee-documents/cv.pdf', 'CV');

    $this->artisan('storage:depublic-employee-files', ['--include' => 'avatars'])
        ->expectsOutputToContain('moved=1')
        ->assertSuccessful();

    // Avatar moved
    expect(Storage::disk('public')->exists('avatars/alice.jpg'))->toBeFalse();
    expect(Storage::disk('local')->exists('avatars/alice.jpg'))->toBeTrue();

    // employee-documents NOT touched
    expect(Storage::disk('public')->get('employee-documents/cv.pdf'))->toBe('CV');
    expect(Storage::disk('local')->exists('employee-documents/cv.pdf'))->toBeFalse();
});

it('reports cleanly when source directories are empty', function () {
    $this->artisan('storage:depublic-employee-files')
        ->expectsOutputToContain('no files on public disk')
        ->expectsOutputToContain('moved=0')
        ->assertSuccessful();
});

it('errors when --include is empty', function () {
    $this->artisan('storage:depublic-employee-files', ['--include' => ''])
        ->expectsOutputToContain('No directories to migrate')
        ->assertFailed();
});
