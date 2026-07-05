<?php

namespace App\Models;

use App\Enums\AllowanceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Allowance extends Model
{
    use SoftDeletes;

    /** Allowance calculation methods. */
    public const CALC_FIXED               = 'fixed';
    public const CALC_PERCENT_OF_BASIC    = 'percent_of_basic';
    public const CALC_PERCENT_OF_EMOLUMENT = 'percent_of_emolument';

    protected $fillable = [
        'employee_id', 'type', 'label', 'amount',
        'calc_method', 'rate', 'cap',
        'is_taxable', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'type'           => AllowanceType::class,
            'amount'         => 'decimal:2',
            'rate'           => 'decimal:5',
            'cap'            => 'decimal:2',
            'is_taxable'     => 'bool',
            'effective_from' => 'date',
            'effective_to'   => 'date',
        ];
    }

    /**
     * Resolve the cash value of this allowance for a given basic salary and
     * cash emolument (basic + fixed allowances). Percentage methods apply the
     * rate to the relevant base and clamp to `cap` when set; `fixed` returns the
     * stored amount unchanged.
     */
    public function resolveAmount(float $basic, float $emolument): float
    {
        $value = match ($this->calc_method) {
            self::CALC_PERCENT_OF_BASIC     => (float) $this->rate * $basic,
            self::CALC_PERCENT_OF_EMOLUMENT => (float) $this->rate * $emolument,
            default                         => (float) $this->amount,
        };

        if ($this->cap !== null) {
            $value = min($value, (float) $this->cap);
        }

        return round($value, 2);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeEffectiveOn(Builder $query, \DateTimeInterface|string $date): Builder
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $query
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
