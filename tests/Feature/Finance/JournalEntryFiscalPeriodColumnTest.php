<?php

declare(strict_types=1);

use App\Enums\JournalSourceType;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Finance\FiscalCalendarService;

it('persists fiscal_period_id and exposes the relation', function () {
    $year = app(FiscalCalendarService::class)->ensureYear(2026);
    $jun = FiscalPeriod::where('fiscal_year_id', $year->id)->where('period_no', 6)->firstOrFail();

    $entry = JournalEntry::create([
        'reference'      => 'JE-FP-1',
        'entry_date'     => '2026-06-15',
        'narration'      => 'test',
        'status'         => 'draft',
        'source_type'    => JournalSourceType::Manual->value,
        'source_id'      => null,
        'fiscal_period_id' => $jun->id,
        'created_by'     => User::factory()->create()->id,
    ]);

    expect($entry->fresh()->fiscal_period_id)->toBe($jun->id)
        ->and($entry->fiscalPeriod->period_no)->toBe(6);
});
