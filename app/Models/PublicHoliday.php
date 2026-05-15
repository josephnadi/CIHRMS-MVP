<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PublicHoliday extends Model
{
    protected $fillable = ['jurisdiction', 'holiday_date', 'name', 'is_observed', 'observed_date'];

    protected function casts(): array
    {
        return [
            'holiday_date'  => 'date',
            'observed_date' => 'date',
            'is_observed'   => 'bool',
        ];
    }

    public function scopeInYear(Builder $q, int $year, string $jurisdiction = 'GH'): Builder
    {
        return $q->where('jurisdiction', $jurisdiction)
            ->whereYear('holiday_date', $year);
    }

    /**
     * Cached year-keyed set of observed holiday dates ("Y-m-d") for fast `isHoliday()` checks.
     *
     * @return array<int, string>
     */
    public static function observedDatesInYear(int $year, string $jurisdiction = 'GH'): array
    {
        return Cache::remember("holidays:{$jurisdiction}:{$year}", 3600, function () use ($year, $jurisdiction) {
            return static::inYear($year, $jurisdiction)
                ->get()
                ->map(fn (PublicHoliday $h) => optional($h->observed_date ?? $h->holiday_date)->format('Y-m-d'))
                ->filter()
                ->values()
                ->all();
        });
    }

    public static function isHoliday(\DateTimeInterface|string $date, string $jurisdiction = 'GH'): bool
    {
        $d = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;
        $year = (int) substr($d, 0, 4);
        return in_array($d, static::observedDatesInYear($year, $jurisdiction), true);
    }
}
