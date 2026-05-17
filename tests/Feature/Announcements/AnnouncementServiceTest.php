<?php

declare(strict_types=1);

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementType;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\User;
use App\Services\AnnouncementService;

/**
 * Service-layer tests for the ticker aggregation pipeline.
 *
 * Covers the four ordering / filtering rules that the live ticker depends on:
 *  1. Active-window scoping (starts_at / ends_at)
 *  2. Role-scoped audience filtering
 *  3. Pinned-first → severity-weighted → chronological sort
 *  4. Auto-generated entries (birthdays / events / tasks) layered onto manual notices
 */

beforeEach(function () {
    $this->svc = app(AnnouncementService::class);
});

it('returns only manual announcements that are active right now', function () {
    Announcement::factory()->create(['title' => 'Active', 'is_active' => true]);
    Announcement::factory()->inactive()->create(['title' => 'Inactive']);
    Announcement::factory()->expired()->create(['title' => 'Expired']);
    Announcement::factory()->scheduled(now()->addDays(7))->create(['title' => 'Future']);

    $titles = $this->svc->ticker(null)->pluck('title');

    expect($titles)->toContain('Active');
    expect($titles)->not->toContain('Inactive', 'Expired', 'Future');
});

it('scopes manual announcements by audience role', function () {
    $hrUser = User::factory()->create(['role' => 'hr_admin']);
    $emp    = User::factory()->create(['role' => 'employee']);

    Announcement::factory()->create(['title' => 'Everyone', 'audience_role' => null]);
    Announcement::factory()->forRole('hr_admin')->create(['title' => 'HR-only']);
    Announcement::factory()->forRole('manager')->create(['title' => 'Managers']);

    $hrTitles  = $this->svc->ticker($hrUser)->pluck('title');
    $empTitles = $this->svc->ticker($emp)->pluck('title');

    expect($hrTitles)->toContain('Everyone', 'HR-only');
    expect($hrTitles)->not->toContain('Managers');

    expect($empTitles)->toContain('Everyone');
    expect($empTitles)->not->toContain('HR-only', 'Managers');
});

it('sorts pinned items first regardless of severity', function () {
    Announcement::factory()->urgent()->create(['title' => 'Urgent · not pinned']);
    Announcement::factory()->pinned()->create(['title' => 'Pinned · info-level']);

    $first = $this->svc->ticker(null)->first();

    expect($first['title'])->toBe('Pinned · info-level');
    expect($first['pinned'])->toBeTrue();
});

it('sorts unpinned items by severity weight (urgent before important before info)', function () {
    Announcement::factory()->create(['title' => 'Info']);
    Announcement::factory()->urgent()->create(['title' => 'Urgent']);
    Announcement::factory()->important()->create(['title' => 'Important']);

    $titles = $this->svc->ticker(null)->pluck('title')->all();

    // Pinned > Urgent > Important > Info — none pinned here, so urgency wins.
    expect($titles[0])->toBe('Urgent');
    expect($titles[1])->toBe('Important');
    expect($titles[2])->toBe('Info');
});

it('layers upcoming birthdays from the employees table', function () {
    // DOB exactly today at year boundary → birthday is today
    $today = now();
    Employee::factory()->create(['date_of_birth' => $today->copy()->subYears(30)->setMonth($today->month)->setDay($today->day)]);

    $items = $this->svc->ticker(null);
    $birthdayItems = $items->where('type', AnnouncementType::Birthday->value);

    expect($birthdayItems->count())->toBeGreaterThanOrEqual(1);
    expect($birthdayItems->first()['title'])->toContain('birthday');
});

it('omits birthdays beyond the 7-day horizon', function () {
    $today = now();
    // DOB exactly 30 days from now
    $future = $today->copy()->addDays(30);
    Employee::factory()->create(['date_of_birth' => $future->copy()->subYears(28)]);

    $birthdays = $this->svc->ticker(null)->where('type', AnnouncementType::Birthday->value);
    expect($birthdays)->toHaveCount(0);
});

it('respects the configurable item limit', function () {
    Announcement::factory()->count(30)->create();

    $items = $this->svc->ticker(null, limit: 5);

    expect($items)->toHaveCount(5);
});

it('returns an empty collection when no data exists and no user is supplied', function () {
    $items = $this->svc->ticker(null);
    expect($items)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($items)->toHaveCount(0);
});
