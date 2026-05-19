<?php

use App\Models\Employee;
use App\Models\IncidentReport;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function asEmployee(): array {
    $user = User::factory()->create();
    $emp  = Employee::factory()->create(['user_id' => $user->id]);
    return [$user, $emp];
}

function asReviewer(): User {
    return User::factory()->create(['permissions' => ['incidents.review']]);
}

function aReport(?User $submitterUser = null, ?Employee $emp = null): IncidentReport {
    if (! $submitterUser) {
        [$submitterUser, $emp] = asEmployee();
    }
    return IncidentReport::create([
        'employee_id' => $emp->id,
        'category'    => 'grievance',
        'title'       => 'Concern about overtime policy',
        'body'        => 'I would like to discuss the overtime policy as it has been applied inconsistently.',
        'status'      => 'open',
    ]);
}

test('it_lets_an_employee_submit_an_incident_report', function () {
    [$user] = asEmployee();
    $this->actingAs($user)
        ->post(route('incidents.store'), [
            'category' => 'grievance',
            'title'    => 'Concern about overtime policy',
            'body'     => 'I would like to discuss the overtime policy because it has been applied inconsistently.',
        ])
        ->assertRedirect();

    $r = IncidentReport::latest()->first();
    expect($r)->not->toBeNull();
    expect($r->status->value)->toBe('open');
    expect($r->assignees()->count())->toBe(0);
});

test('it_rejects_submission_without_a_category', function () {
    [$user] = asEmployee();
    $this->actingAs($user)
        ->post(route('incidents.store'), [
            'title' => 'X X X X X X', 'body' => str_repeat('y', 25),
        ])
        ->assertSessionHasErrors('category');
});

test('it_rejects_an_attachment_over_10mb', function () {
    Storage::fake('incidents');
    [$user] = asEmployee();
    $this->actingAs($user)
        ->post(route('incidents.store'), [
            'category' => 'other', 'title' => 'X X X X X X',
            'body' => str_repeat('y', 25),
            'attachments' => [UploadedFile::fake()->create('big.pdf', 12000)],
        ])
        ->assertSessionHasErrors('attachments.0');
});

test('it_persists_attachments_on_the_private_disk', function () {
    Storage::fake('incidents');
    [$user] = asEmployee();
    $this->actingAs($user)
        ->post(route('incidents.store'), [
            'category' => 'safety',
            'title'    => 'Wet floor in lobby',
            'body'     => 'There has been a wet floor in the lobby for two days without signage.',
            'attachments' => [UploadedFile::fake()->image('photo.jpg', 800, 600)],
        ])
        ->assertRedirect();

    $r = IncidentReport::latest()->first();
    expect($r->attachments()->count())->toBe(1);
    Storage::disk('incidents')->assertExists($r->attachments->first()->file_path);
});

test('submitter_can_view_their_own_report', function () {
    [$user, $emp] = asEmployee();
    $r = aReport($user, $emp);
    $this->actingAs($user)->get(route('incidents.show', $r))->assertOk();
});

test('unrelated_employee_cannot_view_a_report', function () {
    $r = aReport();
    [$stranger] = asEmployee();
    $this->actingAs($stranger)->get(route('incidents.show', $r))->assertForbidden();
});

test('super_admin_without_assignment_cannot_view_a_report', function () {
    $r = aReport();
    $sa = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs($sa)->get(route('incidents.show', $r))->assertForbidden();
});

test('assignee_can_view_an_assigned_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($rev)->get(route('incidents.show', $r))->assertOk();
});

test('removed_assignee_can_no_longer_view_the_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $r->assignees()->updateExistingPivot($rev->id, ['removed_at' => now()]);
    $this->actingAs($rev)->get(route('incidents.show', $r))->assertForbidden();
});

test('only_users_with_incidents_review_can_be_assigned', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $randomUser = User::factory()->create();
    $this->actingAs($subUser)
        ->post(route('incidents.assign', $r), ['user_id' => $randomUser->id])
        ->assertSessionHasErrors('user_id');
});

test('first_assignment_transitions_status_to_in_review', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $this->actingAs($subUser)
        ->post(route('incidents.assign', $r), ['user_id' => $rev->id])
        ->assertRedirect();
    expect($r->fresh()->status->value)->toBe('in_review');
});

test('assignee_can_post_a_message', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($rev)
        ->post(route('incidents.messages.store', $r), ['body' => 'Thanks, will look into this.'])
        ->assertRedirect();
    expect($r->messages()->count())->toBe(1);
});

test('submitter_can_post_a_message', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($subUser)
        ->post(route('incidents.messages.store', $r), ['body' => 'Additional context: this happened on Friday.'])
        ->assertRedirect();
});

test('non_member_cannot_post_a_message', function () {
    $r = aReport();
    [$stranger] = asEmployee();
    $this->actingAs($stranger)
        ->post(route('incidents.messages.store', $r), ['body' => 'Hi.'])
        ->assertForbidden();
});

test('assignee_can_close_a_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($rev)
        ->post(route('incidents.close', $r), ['resolution_note' => 'Discussed and addressed in 1:1.'])
        ->assertRedirect();
    expect($r->fresh()->status->value)->toBe('closed');
    expect($r->fresh()->closed_at)->not->toBeNull();
});

test('submitter_cannot_close_their_own_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $this->actingAs($subUser)
        ->post(route('incidents.close', $r), ['resolution_note' => null])
        ->assertForbidden();
});

test('assignee_can_reopen_a_closed_report', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $r->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_id' => $rev->id]);
    $this->actingAs($rev)->post(route('incidents.reopen', $r))->assertRedirect();
    expect($r->fresh()->status->value)->toBe('in_review');
});

test('closing_locks_the_thread', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);
    $r->update(['status' => 'closed']);
    $this->actingAs($subUser)
        ->post(route('incidents.messages.store', $r), ['body' => 'Wait, one more thing.'])
        ->assertForbidden();
});

test('attachment_download_requires_view_permission', function () {
    Storage::fake('incidents');
    [$subUser, $emp] = asEmployee();
    $this->actingAs($subUser)
        ->post(route('incidents.store'), [
            'category' => 'safety', 'title' => 'Wet floor in lobby xyz',
            'body' => str_repeat('a', 25),
            'attachments' => [UploadedFile::fake()->image('photo.jpg')],
        ])
        ->assertRedirect();
    $att = IncidentReport::latest()->first()->attachments()->first();

    [$stranger] = asEmployee();
    $this->actingAs($stranger)->get(route('incidents.attachments.download', $att))->assertForbidden();

    $this->actingAs($subUser)->get(route('incidents.attachments.download', $att))->assertOk();
});

test('assigning_a_user_fires_assigned_notification', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $this->actingAs($subUser)
        ->post(route('incidents.assign', $r), ['user_id' => $rev->id])
        ->assertRedirect();
    // Polymorphic notifications table: notifiable_id holds the user id,
    // notifiable_type holds the User model class, type holds the event kind.
    expect(
        Notification::where('notifiable_id', $rev->id)
            ->where('notifiable_type', User::class)
            ->where('type', 'incident.assigned')
            ->exists()
    )->toBeTrue();
});

test('posting_a_message_notifies_other_circle_members_but_not_author', function () {
    [$subUser, $emp] = asEmployee();
    $r = aReport($subUser, $emp);
    $rev = asReviewer();
    $r->assignees()->attach($rev->id, ['assigned_at' => now(), 'assigned_by_id' => $subUser->id]);

    $this->actingAs($rev)
        ->post(route('incidents.messages.store', $r), ['body' => 'On it.'])
        ->assertRedirect();

    // The submitter (subUser) should receive exactly one notification;
    // the author (rev) should receive none.
    expect(
        Notification::where('notifiable_id', $subUser->id)
            ->where('notifiable_type', User::class)
            ->where('type', 'incident.message')
            ->count()
    )->toBe(1);

    expect(
        Notification::where('notifiable_id', $rev->id)
            ->where('notifiable_type', User::class)
            ->where('type', 'incident.message')
            ->count()
    )->toBe(0);
});
