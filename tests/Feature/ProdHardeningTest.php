<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => (new RolePermissionSeeder())->run());

// ── Audit-log immutability ──────────────────────────────────────────────────

function makeAuditLog(): AuditLog
{
    return AuditLog::create([
        'user_id' => null, 'action' => 'test.action', 'route_name' => 'x',
        'method' => 'POST', 'path' => '/x', 'ip_address' => '127.0.0.1',
        'user_agent' => 'pest', 'payload' => [], 'chain_position' => 1,
        'previous_hash' => str_repeat('0', 64), 'row_hash' => str_repeat('a', 64),
    ]);
}

it('blocks updates to an audit log', function () {
    $log = makeAuditLog();
    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(RuntimeException::class);
    expect($log->fresh()->action)->toBe('test.action');
});

it('blocks deletion of an audit log', function () {
    $log = makeAuditLog();
    expect(fn () => $log->delete())->toThrow(RuntimeException::class);
    expect(AuditLog::find($log->id))->not->toBeNull();
});

// ── Applicant CV: private disk + authorized download ─────────────────────────

it('stores an uploaded CV on the private disk, not the public one', function () {
    Storage::fake('local');
    Storage::fake('public');

    $job = JobPosting::factory()->create();
    $applicant = User::factory()->create(['role' => 'employee', 'permissions' => ['recruitment.apply']]);

    $this->actingAs($applicant)->post(route('jobs.apply', $job), [
        'name' => 'Ama Mensah', 'email' => 'ama@example.test',
        'cv' => UploadedFile::fake()->create('cv.pdf', 40, 'application/pdf'),
    ])->assertRedirect();

    $cvPath = Applicant::firstOrFail()->cv_path;
    expect($cvPath)->not->toBeNull();
    Storage::disk('local')->assertExists($cvPath);
    Storage::disk('public')->assertMissing($cvPath);
});

it('serves a CV only to a user with recruitment.manage', function () {
    Storage::fake('local');
    Storage::disk('local')->put('applicant-cvs/secret.pdf', 'CV_BYTES');
    $applicant = Applicant::factory()->create(['cv_path' => 'applicant-cvs/secret.pdf']);

    $outsider = User::factory()->create(['role' => 'employee', 'permissions' => []]);
    $this->actingAs($outsider)->get(route('applicants.cv', $applicant))->assertForbidden();

    $recruiter = User::factory()->create(['role' => 'hr_admin', 'permissions' => ['recruitment.manage']]);
    $this->actingAs($recruiter)->get(route('applicants.cv', $applicant))
        ->assertOk()
        ->assertDownload();
});

it('404s a CV download when the file is missing', function () {
    Storage::fake('local');
    $applicant = Applicant::factory()->create(['cv_path' => 'applicant-cvs/gone.pdf']);
    $recruiter = User::factory()->create(['role' => 'hr_admin', 'permissions' => ['recruitment.manage']]);

    $this->actingAs($recruiter)->get(route('applicants.cv', $applicant))->assertNotFound();
});
