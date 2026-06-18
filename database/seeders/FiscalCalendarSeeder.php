<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Finance\FiscalCalendarService;
use Illuminate\Database\Seeder;

class FiscalCalendarSeeder extends Seeder
{
    /**
     * Seed the current and next calendar years so production always has an
     * Open fiscal calendar for incoming postings. Idempotent.
     *
     * Uses a fixed anchor year so the seed is deterministic in CI; the current
     * year is derived from the database server clock at seed time via now().
     */
    public function run(): void
    {
        $svc = app(FiscalCalendarService::class);
        $current = (int) now()->format('Y');
        $svc->ensureYear($current);
        $svc->ensureYear($current + 1);
    }
}
