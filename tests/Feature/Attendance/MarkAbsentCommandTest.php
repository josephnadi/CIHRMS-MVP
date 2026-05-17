<?php

declare(strict_types=1);

use App\Models\Employee;
use Illuminate\Support\Carbon;

use function Pest\Laravel\artisan;

it('creates a summary row for every active employee with no records on the given date', function () {
    Carbon::setTestNow('2026-06-15 23:59'); // Monday
    Employee::factory()->active()->count(3)->create();

    artisan('attendance:mark-absent --date=2026-06-15')->assertSuccessful();

    expect(\App\Models\AttendanceSummary::whereDate('summary_date', '2026-06-15')->count())->toBe(3);

    Carbon::setTestNow();
});
