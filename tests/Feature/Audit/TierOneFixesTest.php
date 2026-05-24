<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\DataSubjectRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder())->run();
});

// ── Privacy/MyRequests no longer crashes on paginator-vs-array mismatch ──

it('Privacy/MyRequests renders successfully when the user has requests', function () {
    $u = User::factory()->create(['role' => 'employee']);

    DataSubjectRequest::create([
        'reference'             => 'DSR-2026-0001',
        'subject_user_id'       => $u->id,
        'request_type'          => 'access',
        'subject_statement'     => 'I would like a copy of my data.',
        'status'                => 'submitted',
        'submitted_at'          => now(),
        'target_completion_date'=> now()->addDays(30),
    ]);

    $this->actingAs($u)
        ->get('/privacy')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Privacy/MyRequests')
            ->has('requests', 1)              // array shape — used to be paginator object
            ->where('requests.0.reference', 'DSR-2026-0001')
        );
});

it('Privacy/MyRequests renders successfully when the user has no requests', function () {
    $u = User::factory()->create(['role' => 'employee']);

    $this->actingAs($u)
        ->get('/privacy')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Privacy/MyRequests')->has('requests', 0));
});

// ── Privacy admin fulfill / reject — forms posted from Vue must match server contracts ──

it('Privacy/Admin fulfill no longer fails validation when posting the renamed `summary` field', function () {
    $dpo  = User::factory()->create(['role' => 'auditor']);  // auditor holds privacy.fulfill
    $dpo->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(\App\Services\Auth\TwoFactorService::class)->markFresh($dpo);

    $req = DataSubjectRequest::create([
        'reference'              => 'DSR-2026-FUL1',
        'subject_user_id'        => User::factory()->create()->id,
        'request_type'           => 'access',
        'subject_statement'      => 'Access request.',
        'status'                 => 'acknowledged',   // ready to fulfill
        'submitted_at'           => now()->subDays(2),
        'target_completion_date' => now()->addDays(28),
        'assigned_to'            => $dpo->id,
    ]);

    // Old form posted `decision_summary`, server required `summary` — always 422.
    // New form posts `summary` — should NOT 422 here.
    $response = $this->actingAs($dpo)
        ->post("/privacy/admin/{$req->id}/fulfill", [
            'summary' => 'Subject access request fulfilled — ZIP export generated and downloadable for 7 days.',
        ]);

    // Could redirect to back (success) or back with errors (DomainException) — what matters
    // is the validation layer no longer rejects the payload outright.
    $response->assertSessionDoesntHaveErrors('summary');
});

it('Privacy/Admin reject no longer fails validation when posting both required fields', function () {
    $dpo = User::factory()->create(['role' => 'auditor']);
    $dpo->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(\App\Services\Auth\TwoFactorService::class)->markFresh($dpo);

    $req = DataSubjectRequest::create([
        'reference'              => 'DSR-2026-REJ1',
        'subject_user_id'        => User::factory()->create()->id,
        'request_type'           => 'erasure',
        'subject_statement'      => 'Please delete my data.',
        'status'                 => 'acknowledged',
        'submitted_at'           => now()->subDays(2),
        'target_completion_date' => now()->addDays(28),
        'assigned_to'            => $dpo->id,
    ]);

    // Old form was missing `summary` entirely — always 422.
    $response = $this->actingAs($dpo)
        ->post("/privacy/admin/{$req->id}/reject", [
            'statutory_basis' => 'Act 843 §27(e) — public-interest archive of payroll records',
            'summary'         => 'Erasure refused on the basis of the statutory archive obligation.',
        ]);

    $response->assertSessionDoesntHaveErrors(['statutory_basis', 'summary']);
});

// ── Establishment/Positions/Show now exists ──

it('Establishment/Positions/Show renders without 404', function () {
    $u = User::factory()->create(['role' => 'hr_admin']);
    $dept = Department::firstOrCreate(['code' => 'HR'], ['name' => 'Human Resources']);

    $position = Position::create([
        'code'              => 'POS-CEO',
        'title'             => 'Chief Executive Officer',
        'department_id'     => $dept->id,
        'headcount_ceiling' => 1,
        'status'            => 'vacant',
    ]);

    $this->actingAs($u)
        ->get("/positions/{$position->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Establishment/Positions/Show'));
});

// ── TicketService search works on SQLite (no more `ilike` 500) ──

it('TicketService search handles a mixed-case term on SQLite without 500', function () {
    $u = User::factory()->create(['role' => 'finance_officer']);
    $dept = Department::firstOrCreate(['code' => 'HR'], ['name' => 'Human Resources']);
    $emp = Employee::factory()->create(['user_id' => $u->id, 'department_id' => $dept->id]);

    Ticket::factory()->create([
        'employee_id' => $emp->id,
        'title'       => 'PRINTER not working in HR office',
        'description' => 'The printer keeps jamming.',
    ]);

    $this->actingAs($u)
        ->get('/tickets?search=printer')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Tickets/Index'));
});

// ── Conversation::otherParticipant returns the right user (broken !== operator) ──

it('Conversation::otherParticipant returns the partner in a 1:1 chat', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();

    $conv = Conversation::create(['is_group' => false]);
    $conv->participants()->attach([$me->id, $them->id]);

    $other = $conv->fresh('participants')->otherParticipant($me);

    expect($other)->not->toBeNull();
    expect($other->id)->toBe($them->id);
});

it('Conversation::otherParticipant returns null for group chats', function () {
    $me = User::factory()->create();

    $conv = Conversation::create(['is_group' => true]);
    $conv->participants()->attach([$me->id, User::factory()->create()->id, User::factory()->create()->id]);

    expect($conv->fresh('participants')->otherParticipant($me))->toBeNull();
});
