<?php

declare(strict_types=1);

use App\Enums\DataSubjectRequestStatus;
use App\Enums\DataSubjectRequestType;
use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Notifications\DpaVerificationLink;
use Illuminate\Support\Facades\Notification;

it('renders the public DPA submission form unauthenticated', function () {
    $this->get(route('dpa.form'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dpa/Submit'));
});

it('accepts a public submission and emails a verification link', function () {
    Notification::fake();

    $this->post(route('dpa.submit'), [
        'subject_email'     => 'ex.employee@example.com',
        'subject_full_name' => 'Yaa Mensah',
        'request_type'      => DataSubjectRequestType::Access->value,
        'subject_statement' => 'I want a copy of all personal data you hold about me.',
    ])->assertRedirectContains('confirmation');

    $req = DataSubjectRequest::sole();
    expect($req->status)->toBe(DataSubjectRequestStatus::PendingVerification);
    expect($req->subject_user_id)->toBeNull();
    expect($req->subject_email)->toBe('ex.employee@example.com');
    expect($req->subject_full_name)->toBe('Yaa Mensah');
    expect($req->verification_token)->not->toBeNull();
    expect($req->verified_at)->toBeNull();

    Notification::assertSentOnDemand(DpaVerificationLink::class);
});

it('rejects submissions missing required fields', function () {
    $this->post(route('dpa.submit'), [
        'subject_email'     => 'not-an-email',
        'subject_full_name' => 'A',
        'request_type'      => 'invalid',
        'subject_statement' => 'too short',
    ])->assertSessionHasErrors(['subject_email', 'subject_full_name', 'request_type', 'subject_statement']);
});

it('verifies a public request via the emailed token and starts the SLA clock', function () {
    $req = DataSubjectRequest::create([
        'reference'              => 'DSR-2026-99999',
        'subject_user_id'        => null,
        'subject_email'          => 'kofi@example.com',
        'subject_full_name'      => 'Kofi Asante',
        'verification_token'     => 'tok-abc-123',
        'request_type'           => DataSubjectRequestType::Erasure->value,
        'status'                 => DataSubjectRequestStatus::PendingVerification->value,
        'subject_statement'      => 'Please erase my data',
        'submitted_at'           => now()->subDays(5),
        // Original target was 30 days from submission — should reset on verify
        'target_completion_date' => now()->subDays(5)->addDays(30)->toDateString(),
    ]);

    $this->get(route('dpa.verify', ['token' => 'tok-abc-123']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dpa/Verified')
            ->where('ok', true)
            ->where('reference', 'DSR-2026-99999'));

    $req->refresh();
    expect($req->status)->toBe(DataSubjectRequestStatus::Submitted);
    expect($req->verified_at)->not->toBeNull();
    // SLA clock reset from verification, not original submission
    expect($req->target_completion_date->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('rejects verification with an unknown token without leaking detail', function () {
    DataSubjectRequest::create([
        'reference'              => 'DSR-2026-88888',
        'subject_user_id'        => null,
        'subject_email'          => 'real@example.com',
        'subject_full_name'      => 'Real Person',
        'verification_token'     => 'real-token',
        'request_type'           => DataSubjectRequestType::Access->value,
        'status'                 => DataSubjectRequestStatus::PendingVerification->value,
        'subject_statement'      => 'x',
        'submitted_at'           => now(),
        'target_completion_date' => now()->addDays(30)->toDateString(),
    ]);

    $this->get(route('dpa.verify', ['token' => 'wrong-token']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dpa/Verified')->where('ok', false));
});

it('returns a request via track when reference + email both match', function () {
    DataSubjectRequest::create([
        'reference'              => 'DSR-2026-11111',
        'subject_user_id'        => null,
        'subject_email'          => 'find.me@example.com',
        'subject_full_name'      => 'Find Me',
        'verification_token'     => 'x',
        'verified_at'            => now()->subDay(),
        'request_type'           => DataSubjectRequestType::Access->value,
        'status'                 => DataSubjectRequestStatus::Submitted->value,
        'subject_statement'      => 'x',
        'submitted_at'           => now()->subDay(),
        'target_completion_date' => now()->addDays(29)->toDateString(),
    ]);

    $this->post(route('dpa.track.submit'), [
        'reference'     => 'DSR-2026-11111',
        'subject_email' => 'find.me@example.com',
    ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dpa/Track')
            ->where('result.reference', 'DSR-2026-11111')
            ->where('result.status', 'submitted'));
});

it('returns not_found when reference matches but email does not', function () {
    DataSubjectRequest::create([
        'reference'              => 'DSR-2026-22222',
        'subject_user_id'        => null,
        'subject_email'          => 'owner@example.com',
        'subject_full_name'      => 'Owner',
        'verification_token'     => 'x',
        'request_type'           => DataSubjectRequestType::Access->value,
        'status'                 => DataSubjectRequestStatus::Submitted->value,
        'subject_statement'      => 'x',
        'submitted_at'           => now(),
        'target_completion_date' => now()->addDays(30)->toDateString(),
    ]);

    $this->post(route('dpa.track.submit'), [
        'reference'     => 'DSR-2026-22222',
        'subject_email' => 'attacker@example.com', // wrong email
    ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dpa/Track')
            ->where('result.not_found', true));
});

it('also tracks authenticated submissions by matching the linked User email', function () {
    $user = User::factory()->create(['email' => 'authd@example.com']);

    DataSubjectRequest::create([
        'reference'              => 'DSR-2026-33333',
        'subject_user_id'        => $user->id,
        'request_type'           => DataSubjectRequestType::Portability->value,
        'status'                 => DataSubjectRequestStatus::Acknowledged->value,
        'subject_statement'      => 'x',
        'submitted_at'           => now(),
        'target_completion_date' => now()->addDays(30)->toDateString(),
    ]);

    $this->post(route('dpa.track.submit'), [
        'reference'     => 'DSR-2026-33333',
        'subject_email' => 'authd@example.com',
    ])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dpa/Track')
            ->where('result.reference', 'DSR-2026-33333')
            ->where('result.status', 'acknowledged'));
});

it('never exposes the verification_token in tracking responses', function () {
    $req = DataSubjectRequest::create([
        'reference'              => 'DSR-2026-44444',
        'subject_user_id'        => null,
        'subject_email'          => 'leaktest@example.com',
        'subject_full_name'      => 'Leak Test',
        'verification_token'     => 'TOKEN-SHOULD-NEVER-LEAK',
        'request_type'           => DataSubjectRequestType::Access->value,
        'status'                 => DataSubjectRequestStatus::PendingVerification->value,
        'subject_statement'      => 'x',
        'submitted_at'           => now(),
        'target_completion_date' => now()->addDays(30)->toDateString(),
    ]);

    $response = $this->post(route('dpa.track.submit'), [
        'reference'     => $req->reference,
        'subject_email' => 'leaktest@example.com',
    ]);

    expect($response->getContent())->not->toContain('TOKEN-SHOULD-NEVER-LEAK');
});

it('the throttle middleware caps abusive submission rates', function () {
    // The route group is throttled at 10/min — make 11 requests, the last one
    // should be 429. We don't fake notifications here so each submission's
    // queued mail is dispatched but discarded in the test mail transport.
    for ($i = 0; $i < 10; $i++) {
        $this->post(route('dpa.submit'), [
            'subject_email'     => "spammer{$i}@example.com",
            'subject_full_name' => "Spammer {$i}",
            'request_type'      => DataSubjectRequestType::Access->value,
            'subject_statement' => 'spam '.str_repeat('x', 50),
        ])->assertRedirect();
    }

    $this->post(route('dpa.submit'), [
        'subject_email'     => 'spammer-eleventh@example.com',
        'subject_full_name' => 'Spammer 11',
        'request_type'      => DataSubjectRequestType::Access->value,
        'subject_statement' => 'spam '.str_repeat('x', 50),
    ])->assertStatus(429);
});
