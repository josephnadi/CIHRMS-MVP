<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StatutoryRate extends Model
{
    public const SSNIT_EMPLOYER          = 'SSNIT_EMPLOYER';          // 13% of basic
    public const SSNIT_EMPLOYEE          = 'SSNIT_EMPLOYEE';          // 5.5% of basic
    public const NHIA_SPLIT              = 'NHIA_SPLIT';              // 2.5% of basic — routed via SSNIT employer
    public const TIER2_EMPLOYER          = 'TIER2_EMPLOYER';          // 5% of basic
    public const TIER3_MAX_COMBINED      = 'TIER3_MAX_COMBINED';      // 16.5% cap of basic for tax-relief
    public const MAX_INSURABLE_EARNINGS  = 'MAX_INSURABLE_EARNINGS';  // GHS cap on Tier-1 base
    public const REMITTANCE_DEADLINE_DAYS = 'REMITTANCE_DEADLINE_DAYS'; // 14 days

    protected $fillable = [
        'code', 'label', 'rate', 'is_rate', 'currency',
        'effective_from', 'effective_to', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'rate'           => 'decimal:6',
            'is_rate'        => 'bool',
            'effective_from' => 'date',
            'effective_to'   => 'date',
            'meta'           => 'array',
        ];
    }

    public function scopeEffectiveOn(Builder $query, \DateTimeInterface|string $date): Builder
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $query
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    public static function lookup(string $code, \DateTimeInterface|string $date): float
    {
        $row = static::where('code', $code)->effectiveOn($date)->orderByDesc('effective_from')->first();

        if (! $row) {
            throw new \RuntimeException("No statutory rate '{$code}' effective on " .
                ($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date));
        }

        return (float) $row->rate;
    }
}
