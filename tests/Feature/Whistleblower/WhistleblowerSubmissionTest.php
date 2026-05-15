<?php

use App\Models\User;
use App\Models\WhistleblowerReport;
use App\Services\Whistleblower\WhistleblowerSubmissionService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->svc = app(WhistleblowerSubmissionService::class);
});

it('creates an anonymous report and returns a tracking code', function () {
    $result = $this->svc->submit([
        'category'        => 'corruption',
        'subject_summary' => 'Procurement irregularity',
        'description'     => 'I observed payments without competitive bidding between Jan and March 2026.',
        'is_anonymous'    => true,
    ]);

    expect($result['tracking_code'])->toBeString()->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/');
    expect($result['report']->case_number)->toStartWith('WB-');
    expect($result['report']->is_anonymous)->toBeTrue();
    expect($result['report']->submitter_user_id)->toBeNull();
});

it('NEVER stores the plaintext tracking code anywhere — only its sha256 hash', function () {
    $result = $this->svc->submit([
        'category'        => 'fraud',
        'subject_summary' => 'Inventory shrinkage',
        'description'     => 'Stock counts have been falsified to hide ongoing theft from the warehouse.',
        'is_anonymous'    => true,
    ]);

    $row = DB::table('whistleblower_reports')->where('id', $result['report']->id)->first();

    // Plaintext code never present in any column
    expect($row->tracking_token_hash)->not->toContain($result['tracking_code']);
    expect($row->tracking_token_hash)->toHaveLength(64); // sha256 hex
    expect($row->tracking_token_hash)->toBe(hash('sha256', str_replace('-', '', $result['tracking_code'])));
});

it('forces submitter_user_id to NULL on anonymous submissions even if a user is passed', function () {
    $user = User::factory()->create();

    $result = $this->svc->submit(
        payload: [
            'category'        => 'harassment',
            'subject_summary' => 'Manager misconduct',
            'description'     => 'My line manager has been making inappropriate comments in private meetings.',
            'is_anonymous'    => true,
        ],
        authenticatedUser: $user, // even though we pass a user, anonymity wins
    );

    expect($result['report']->submitter_user_id)->toBeNull();
    expect($result['report']->is_anonymous)->toBeTrue();
});

it('encrypts description, desired_outcome, and submitter_contact at rest', function () {
    $result = $this->svc->submit([
        'category'         => 'safety',
        'subject_summary'  => 'Exposed wiring on second floor',
        'description'      => 'Multiple cables hang exposed near the stairwell — visible from the elevator lobby.',
        'desired_outcome'  => 'Have facilities cordon off the area today.',
        'is_anonymous'     => false,
        'submitter_contact'=> 'joe@example.com',
    ]);

    $raw = DB::table('whistleblower_reports')->where('id', $result['report']->id)->first();

    // Ciphertext on the row, not the plaintext we submitted
    expect($raw->description)->not->toContain('exposed near the stairwell');
    expect($raw->description)->toStartWith('eyJ'); // Laravel encrypted payloads are base64 JSON starting with `eyJ`
    expect($raw->submitter_contact)->not->toContain('joe@example.com');

    // Decryption via Eloquent reads still works
    $fresh = WhistleblowerReport::find($result['report']->id);
    expect($fresh->description)->toContain('exposed near the stairwell');
    expect($fresh->submitter_contact)->toBe('joe@example.com');
});

it('looks up a report by tracking code', function () {
    $result = $this->svc->submit([
        'category'        => 'fraud',
        'subject_summary' => 'Invoice manipulation',
        'description'     => 'Vendor invoices appear to have been duplicated and rebooked under different vendor codes.',
        'is_anonymous'    => true,
    ]);

    $found = WhistleblowerReport::findByTrackingCode($result['tracking_code']);
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($result['report']->id);

    // Wrong code returns null (no leak)
    expect(WhistleblowerReport::findByTrackingCode('XXXX-XXXX-XXXX'))->toBeNull();
});

it('case numbers increment per year', function () {
    $a = $this->svc->submit(['category' => 'other', 'subject_summary' => 'A', 'description' => str_repeat('a', 50), 'is_anonymous' => true]);
    $b = $this->svc->submit(['category' => 'other', 'subject_summary' => 'B', 'description' => str_repeat('b', 50), 'is_anonymous' => true]);

    expect($a['report']->case_number)->toBe(sprintf('WB-%04d-00001', now()->year));
    expect($b['report']->case_number)->toBe(sprintf('WB-%04d-00002', now()->year));
});
