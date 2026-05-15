<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Attendance\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarkAbsentEmployees extends Command
{
    protected $signature = 'attendance:mark-absent {--date= : ISO date; defaults to today}';
    protected $description = 'Materializes attendance_summaries rows for any active employee without a record on the given date (or today). Honors approved leave.';

    public function handle(AttendanceService $attendance): int
    {
        $date = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))
            : CarbonImmutable::today();

        $count = 0;
        Employee::active()->each(function (Employee $emp) use ($attendance, $date, &$count) {
            $attendance->recomputeDailySummary($emp, $date->toDateString());
            $count++;
        });

        $this->info("Marked attendance for {$count} employees on {$date->toDateString()}");
        return self::SUCCESS;
    }
}
