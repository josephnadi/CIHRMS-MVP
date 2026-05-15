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

    protected $fillable = [
        'employee_id', 'type', 'label', 'amount',
        'is_taxable', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'type'           => AllowanceType::class,
            'amount'         => 'decimal:2',
            'is_taxable'     => 'bool',
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
}
