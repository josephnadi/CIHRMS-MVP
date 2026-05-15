<?php

namespace Database\Seeders;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Ghana statutory public holidays for 2026.
 *
 * Source: Public Holidays Act 2001 (Act 601) + the Holidays (Amendment) Act 2019.
 * Movable holidays (Eid al-Fitr, Eid al-Adha) are best-effort estimates and
 * should be confirmed by the Ministry of the Interior each year.
 *
 * Rule for `observed_date`: if a holiday falls on a Sunday, observance moves
 * to the following Monday. If Saturday, it stays on Saturday (no shift).
 */
class GhanaPublicHolidaySeeder extends Seeder
{
    private const HOLIDAYS_2026 = [
        ['2026-01-01', "New Year's Day"],
        ['2026-01-07', 'Constitution Day'],
        ['2026-03-06', 'Independence Day'],
        ['2026-03-20', 'Eid al-Fitr (estimated)'],
        ['2026-04-03', 'Good Friday'],
        ['2026-04-06', 'Easter Monday'],
        ['2026-05-01', 'May Day / Workers\' Day'],
        ['2026-05-27', 'Eid al-Adha (estimated)'],
        ['2026-08-04', 'Founders\' Day'],
        ['2026-09-21', 'Kwame Nkrumah Memorial Day'],
        ['2026-12-04', 'Farmers\' Day (first Friday in December)'],
        ['2026-12-25', 'Christmas Day'],
        ['2026-12-26', 'Boxing Day'],
    ];

    public function run(): void
    {
        foreach (self::HOLIDAYS_2026 as [$date, $name]) {
            $day      = CarbonImmutable::parse($date);
            $observed = $day->isSunday() ? $day->next('Monday') : $day;

            PublicHoliday::updateOrCreate(
                ['jurisdiction' => 'GH', 'holiday_date' => $day->toDateString()],
                [
                    'name'          => $name,
                    'is_observed'   => true,
                    'observed_date' => $observed->toDateString(),
                ],
            );
        }
    }
}
