<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TaxBracket extends Model
{
    protected $fillable = [
        'jurisdiction', 'cadence',
        'lower_bound', 'upper_bound', 'rate',
        'cumulative_tax_at_lower',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'lower_bound'             => 'decimal:2',
            'upper_bound'             => 'decimal:2',
            'rate'                    => 'decimal:4',
            'cumulative_tax_at_lower' => 'decimal:2',
            'effective_from'          => 'date',
            'effective_to'            => 'date',
        ];
    }

    public function scopeEffectiveOn(Builder $query, \DateTimeInterface|string $date, string $jurisdiction = 'GH', string $cadence = 'monthly'): Builder
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $query
            ->where('jurisdiction', $jurisdiction)
            ->where('cadence', $cadence)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderBy('lower_bound');
    }
}
