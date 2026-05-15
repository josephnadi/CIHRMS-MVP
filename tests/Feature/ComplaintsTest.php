<?php

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\User;

beforeEach(function () {
    $this->hr = User::factory()->create(['role' => 'hr_admin']);
    $this->employeeUser = User::factory()->create(['role' => 'employee']);
});

test('a complaint can be filed and is assigned a unique reference', function () {
    $this->actingAs($this->employeeUser)
        ->post(route('complaints.store'), [
            'details'      => 'Unfair shift allocation in the Operations department.',
            'submitted_by' => 'Akua M.',
        ])
        ->assertRedirect();

    $complaint = Complaint::latest('id')->first();

    expect($complaint)->not->toBeNull();
    expect($complaint->reference)->toStartWith('CMP-');
    expect(strlen($complaint->reference))->toBe(12); // CMP- + 8 chars
    expect($complaint->status->value)->toBe(ComplaintStatus::Submitted->value);
});

test('HR admin can update a complaint status', function () {
    $complaint = Complaint::factory()->create();

    $this->actingAs($this->hr)
        ->patch(route('complaints.updateStatus', $complaint), [
            'status' => ComplaintStatus::Resolved->value,
        ])
        ->assertRedirect();

    $complaint->refresh();
    expect($complaint->status->value)->toBe(ComplaintStatus::Resolved->value);
});

test('anyone can track a complaint by reference (public route)', function () {
    $complaint = Complaint::factory()->create();

    $this->get(route('complaints.track', ['reference' => $complaint->reference]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Complaints/Track')
            ->where('complaint.reference', $complaint->reference)
        );
});

test('tracking with a bad reference returns null complaint', function () {
    $this->get(route('complaints.track', ['reference' => 'CMP-BOGUS123']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Complaints/Track')
            ->where('complaint', null)
        );
});
