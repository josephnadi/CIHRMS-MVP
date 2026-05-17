<?php

use App\Enums\DataSubjectRequestStatus;
use App\Enums\DataSubjectRequestType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Services\Privacy\DataSubjectRequestService;

beforeEach(function () {
    $this->svc = app(DataSubjectRequestService::class);
    $this->subject = User::factory()->create(['role' => 'employee']);
    $this->dpo     = User::factory()->create(['role' => 'super_admin']);
});

it('submits a request, generates a reference, and sets a 30-day SLA', function () {
    $req = $this->svc->submit(
        $this->subject,
        DataSubjectRequestType::Access,
        'I would like a copy of all data you hold about me.',
    );

    expect($req->reference)->toStartWith('DSR-');
    expect($req->status)->toBe(DataSubjectRequestStatus::Submitted);
    expect($req->subject_user_id)->toBe($this->subject->id);

    // 30-day SLA — allow ±1 day for any time-zone drift in the test runtime
    $diff = now()->startOfDay()->diffInDays($req->target_completion_date);
    expect($diff)->toEqualWithDelta(DataSubjectRequestService::SLA_DAYS, 1);

    // Audit trail captures the submission event
    expect($req->audit_trail)->toBeArray()->not->toBeEmpty();
    expect($req->audit_trail[0]['action'])->toBe('submitted');
});

it('lets the DPO acknowledge a submitted request', function () {
    $req = $this->svc->submit($this->subject, DataSubjectRequestType::Access, 'access please');
    $req = $this->svc->acknowledge($req, $this->dpo);

    expect($req->status)->toBe(DataSubjectRequestStatus::Acknowledged);
    expect($req->assigned_to)->toBe($this->dpo->id);
    expect($req->acknowledged_at)->not->toBeNull();
});

it('fulfils an Access request by attaching an export bundle with a SHA-256 hash', function () {
    $dept = Department::factory()->create();
    Employee::factory()->create(['user_id' => $this->subject->id, 'department_id' => $dept->id]);

    $req = $this->svc->submit($this->subject, DataSubjectRequestType::Access, 'I want my data');
    $req = $this->svc->fulfilWithExport($req, $this->dpo, 'Bundle generated.');

    expect($req->status)->toBe(DataSubjectRequestStatus::Fulfilled);
    expect($req->export_path)->not->toBeNull();
    expect($req->export_sha256)->toMatch('/^[0-9a-f]{64}$/');
    expect(file_exists($req->export_path))->toBeTrue();

    // Tamper-evidence: hash file should match recorded hash
    expect(hash_file('sha256', $req->export_path))->toBe($req->export_sha256);

    // Cleanup
    @unlink($req->export_path);
});

it('rejects a request with a statutory basis cited', function () {
    $req = $this->svc->submit($this->subject, DataSubjectRequestType::Erasure, 'delete everything');
    $req = $this->svc->reject($req, $this->dpo, 'Act 843 §27(b) — financial regulatory retention');

    expect($req->status)->toBe(DataSubjectRequestStatus::Rejected);
    expect($req->rejection_basis)->toContain('Act 843');
});

it('lets the subject withdraw their own request but not someone else\'s', function () {
    $req = $this->svc->submit($this->subject, DataSubjectRequestType::Access, 'x');

    $other = User::factory()->create();
    expect(fn () => $this->svc->withdraw($req, $other))
        ->toThrow(\DomainException::class, 'Only the subject');

    $req = $this->svc->withdraw($req, $this->subject);
    expect($req->status)->toBe(DataSubjectRequestStatus::Withdrawn);
});

it('marks overdue requests in a nightly sweep', function () {
    $req = $this->svc->submit($this->subject, DataSubjectRequestType::Access, 'x');
    // Backdate the target so it's already past
    $req->update(['target_completion_date' => now()->subDays(2)->toDateString()]);

    $flipped = $this->svc->markOverdueRequests();

    expect($flipped)->toBe(1);
    expect($req->fresh()->status)->toBe(DataSubjectRequestStatus::Overdue);
});
