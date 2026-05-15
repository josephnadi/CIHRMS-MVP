<?php

namespace App\Models;

use App\Enums\DeductionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deduction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'type', 'label', 'amount', 'percentage',
        'cap_balance', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'type'           => DeductionType::class,
            'amount'         => 'decimal:2',
            'percentage'     => 'decimal:4',
            'cap_balance'    => 'decimal:2',
            'effective_from' => 'date',
            'effective_to'   => 'date',
        ];
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

    public function resolveAmount(float $gross): float
    {
        if ($this->amount !== null && (float) $this->amount > 0) {
            return min((float) $this->amount, $this->cap_balance !== null ? (float) $this->cap_balance : (float) $this->amount);
        }

        if ($this->percentage !== null) {
            $computed = $gross * (float) $this->percentage;
            return $this->cap_balance !== null ? min($computed, (float) $this->cap_balance) : $computed;
        }

        return 0.0;
    }
}
