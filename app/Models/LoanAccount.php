<?php

namespace App\Models;

use App\Enums\AmortizationMethod;
use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference', 'employee_id', 'product_id', 'status',
        'principal', 'term_months', 'booked_interest_rate',
        'booked_amortization_method', 'monthly_installment',
        'total_interest', 'total_repayable',
        'disbursed_amount', 'outstanding_balance', 'installments_paid',
        'purpose',
        'applied_by', 'applied_at',
        'approved_by', 'approved_at',
        'disbursed_by', 'disbursed_at',
        'first_repayment_period', 'expected_end_period', 'actual_end_date',
        'rejection_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'                     => LoanStatus::class,
            'booked_amortization_method' => AmortizationMethod::class,
            'principal'                  => 'decimal:2',
            'booked_interest_rate'       => 'decimal:4',
            'monthly_installment'        => 'decimal:2',
            'total_interest'             => 'decimal:2',
            'total_repayable'            => 'decimal:2',
            'disbursed_amount'           => 'decimal:2',
            'outstanding_balance'        => 'decimal:2',
            'installments_paid'          => 'integer',
            'term_months'                => 'integer',
            'applied_at'                 => 'datetime',
            'approved_at'                => 'datetime',
            'disbursed_at'               => 'datetime',
            'first_repayment_period'     => 'date',
            'expected_end_period'        => 'date',
            'actual_end_date'            => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'product_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function guarantors(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'loan_guarantors')
            ->withPivot(['has_consented', 'consented_at'])
            ->withTimestamps();
    }

    public function scopeActiveForRepayment(Builder $q): Builder
    {
        return $q->whereIn('status', [LoanStatus::Disbursed->value, LoanStatus::Repaying->value]);
    }

    public function progress(): float
    {
        if ((float) $this->total_repayable <= 0) return 0.0;
        $paid = (float) $this->total_repayable - (float) $this->outstanding_balance;
        return round(max(0.0, min(1.0, $paid / (float) $this->total_repayable)), 4);
    }
}
