<?php

declare(strict_types=1);

use App\Models\Announcement;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Feature tests for the Announcements admin (Notice-Board) controller.
 *
 * Covers the permission-gated CRUD surface:
 *   GET  /announcements         → index (renders Inertia view + stats payload)
 *   POST /announcements         → store (creates + audit-stamps created_by)
 *   DELETE /announcements/{id}  → destroy (soft-removes from ticker)
 *
 * Each endpoint requires the `announcements.manage` permission (held by
 * super_admin + hr_admin). Other roles must be rejected with 403.
 */

beforeEach(function () {
    $this->hr      = User::factory()->create(['role' => 'hr_admin']);
    $this->employee = User::factory()->create(['role' => 'employee']);
});

it('renders the Notice Board index with stats + breakdowns for managers', function () {
    Announcement::factory()->count(3)->create();
    Announcement::factory()->pinned()->create();
    Announcement::factory()->urgent()->create();
    Announcement::factory()->inactive()->create();

    actingAs($this->hr)
        ->get(route('announcements.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Announcements/Index')
            ->has('announcements.data')
            ->has('stats', fn ($stats) => $stats
                ->where('total', 6)
                ->where('pinned', 1)
                ->has('active_now')
                ->has('scheduled')
                ->has('expired')
                ->etc()
            )
            ->has('typeBreakdown')
            ->has('severityBreakdown')
        );
});

it('forbids non-managers from viewing the Notice Board admin index', function () {
    actingAs($this->employee)
        ->get(route('announcements.index'))
        ->assertForbidden();
});

it('lets a manager publish a notice and stamps the author', function () {
    $payload = [
        'type'         => 'notice',
        'severity'     => 'info',
        'title'        => 'Office closes 1pm Friday for staff retreat.',
        'body'         => 'See HR for transport arrangements.',
        'is_active'    => true,
        'pinned'       => false,
    ];

    actingAs($this->hr)
        ->post(route('announcements.store'), $payload)
        ->assertRedirect();

    $a = Announcement::latest('id')->first();
    expect($a)->not->toBeNull();
    expect($a->title)->toBe($payload['title']);
    expect($a->created_by)->toBe($this->hr->id);
    expect($a->type->value)->toBe('notice');
    expect($a->severity->value)->toBe('info');
});

it('validates the title is required when publishing', function () {
    actingAs($this->hr)
        ->post(route('announcements.store'), [
            'type'     => 'notice',
            'severity' => 'info',
            'title'    => '',
        ])
        ->assertSessionHasErrors(['title']);
});

it('forbids non-managers from publishing notices', function () {
    actingAs($this->employee)
        ->post(route('announcements.store'), [
            'type'     => 'notice',
            'severity' => 'info',
            'title'    => 'I should not be allowed to post this',
        ])
        ->assertForbidden();

    expect(Announcement::count())->toBe(0);
});

it('lets a manager remove a notice from the ticker', function () {
    $a = Announcement::factory()->create();

    actingAs($this->hr)
        ->delete(route('announcements.destroy', $a))
        ->assertRedirect();

    expect(Announcement::find($a->id))->toBeNull();
});

it('forbids non-managers from removing notices', function () {
    $a = Announcement::factory()->create();

    actingAs($this->employee)
        ->delete(route('announcements.destroy', $a))
        ->assertForbidden();

    expect(Announcement::find($a->id))->not->toBeNull();
});

it('honours the start/end window from the publish form', function () {
    $startsAt = now()->addDay()->startOfHour();
    $endsAt   = now()->addDays(7)->startOfHour();

    actingAs($this->hr)
        ->post(route('announcements.store'), [
            'type'      => 'event',
            'severity'  => 'important',
            'title'     => 'All-hands town hall',
            'starts_at' => $startsAt->toDateTimeLocalString(),
            'ends_at'   => $endsAt->toDateTimeLocalString(),
            'pinned'    => true,
            'is_active' => true,
        ])
        ->assertRedirect();

    $a = Announcement::latest('id')->first();
    expect($a->type->value)->toBe('event');
    expect($a->severity->value)->toBe('important');
    expect($a->pinned)->toBeTrue();
    expect($a->starts_at->toDateString())->toBe($startsAt->toDateString());
    expect($a->ends_at->toDateString())->toBe($endsAt->toDateString());
});
