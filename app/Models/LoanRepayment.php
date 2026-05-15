<?php

namespace App\Models;

use App\Enums\LoanRepaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepayment extends Model
{
    protected $fillable = [
        'loan_account_id', 'installment_no', 'due_period',
        'scheduled_amount', 'principal_portion', 'interest_portion',
        'balance_after', 'paid_amount', 'status',
        'payroll_run_id', 'payroll_line_id',
        'posted_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'            => LoanRepaymentStatus::class,
            'due_period'        => 'date',
            'scheduled_amount'  => 'decimal:2',
            'principal_portion' => 'decimal:2',
            'interest_portion'  => 'decimal:2',
            'balance_after'     => 'decimal:2',
            'paid_amount'       => 'decimal:2',
            'installment_no'    => 'integer',
            'posted_at'         => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanAccount::class, 'loan_account_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function payrollLine(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    public function scopeForPeriod(Builder $q, int $year, int $month): Builder
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        return $q->whereDate('due_period', $start);
    }
}
