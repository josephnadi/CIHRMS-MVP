<?php

namespace App\Models;

use App\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinalSettlement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'offboarding_case_id', 'status',
        'basic_salary', 'years_of_service', 'accrued_leave_days', 'working_days_per_month',
        'gratuity', 'severance', 'leave_encashment', 'prorated_13th_month',
        'ex_gratia', 'gross_settlement',
        'outstanding_loans', 'garnishments', 'other_deductions', 'total_deductions',
        'paye_on_settlement', 'net_payable',
        'calculated_by', 'calculated_at', 'approved_by', 'approved_at',
        'payroll_line_id', 'paid_at',
        'breakdown', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'                  => SettlementStatus::class,
            'basic_salary'            => 'decimal:2',
            'years_of_service'        => 'decimal:2',
            'accrued_leave_days'      => 'decimal:2',
            'working_days_per_month'  => 'decimal:2',
            'gratuity'                => 'decimal:2',
            'severance'               => 'decimal:2',
            'leave_encashment'        => 'decimal:2',
            'prorated_13th_month'     => 'decimal:2',
            'ex_gratia'               => 'decimal:2',
            'gross_settlement'        => 'decimal:2',
            'outstanding_loans'       => 'decimal:2',
            'garnishments'            => 'decimal:2',
            'other_deductions'        => 'decimal:2',
            'total_deductions'        => 'decimal:2',
            'paye_on_settlement'      => 'decimal:2',
            'net_payable'             => 'decimal:2',
            'calculated_at'           => 'datetime',
            'approved_at'             => 'datetime',
            'paid_at'                 => 'datetime',
            'breakdown'               => 'array',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(OffboardingCase::class, 'offboarding_case_id');
    }

    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payrollLine(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }
}
