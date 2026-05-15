<?php

namespace App\Models;

use App\Enums\AmortizationMethod;
use App\Enums\LoanProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanProduct extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type',
        'min_amount', 'max_amount',
        'min_term_months', 'max_term_months',
        'annual_interest_rate', 'amortization_method',
        'max_dti_ratio', 'requires_guarantor', 'requires_collateral',
        'approvals_required', 'is_active',
        'description', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'type'                 => LoanProductType::class,
            'amortization_method'  => AmortizationMethod::class,
            'min_amount'           => 'decimal:2',
            'max_amount'           => 'decimal:2',
            'annual_interest_rate' => 'decimal:4',
            'max_dti_ratio'        => 'decimal:4',
            'requires_guarantor'   => 'bool',
            'requires_collateral'  => 'bool',
            'is_active'            => 'bool',
            'effective_from'       => 'date',
            'effective_to'         => 'date',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(LoanAccount::class, 'product_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
