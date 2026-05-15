<?php

use App\Enums\IdentityVerificationStatus;
use App\Events\DuplicateIdentityDetected;
use App\Events\IdentityVerified;
use App\Models\Employee;
use App\Services\Identity\IdentityVerificationService;
use App\Services\Identity\Providers\ManualUploadProvider;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->service = new IdentityVerificationService(new ManualUploadProvider());
});

it('verifies a well-formed Ghana Card via the manual provider', function () {
    Event::fake();

    $employee = Employee::factory()->create();
    $v = $this->service->verify($employee, 'GHA-123456789-1');

    expect($v->status)->toBe(IdentityVerificationStatus::Verified);
    expect($v->ghana_card_hash)->toBe(hash('sha256', 'GHA-123456789-1'));
    Event::assertDispatched(IdentityVerified::class);
});

it('rejects a malformed Ghana Card number', function () {
    $employee = Employee::factory()->create();
    $v = $this->service->verify($employee, '12345');

    expect($v->status)->toBe(IdentityVerificationStatus::Failed);
    expect($v->failure_reason)->toContain('GHA-NNNNNNNNN-N');
});

it('detects duplicate Ghana Cards across two employees', function () {
    Event::fake();

    $a = Employee::factory()->create();
    $b = Employee::factory()->create();

    $this->service->verify($a, 'GHA-987654321-1');
    $this->service->verify($b, 'GHA-987654321-1'); // same card

    Event::assertDispatched(DuplicateIdentityDetected::class);
});
