<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    use HasFactory;
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'position_id', 'grade_id', 'step', 'payment_id',
        'basic', 'allowance_total', 'gross', 'ssnit_base',
        'ssnit_tier1_employee', 'ssnit_tier1_employer', 'nhia_split',
        'tier2_employer', 'tier3_employee', 'paye',
        'voluntary_deductions', 'net',
        'overtime_hours', 'overtime_pay',
        'breakdown', 'status', 'skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'basic'                 => 'decimal:2',
            'allowance_total'       => 'decimal:2',
            'gross'                 => 'decimal:2',
            'ssnit_base'            => 'decimal:2',
            'ssnit_tier1_employee'  => 'decimal:2',
            'ssnit_tier1_employer'  => 'decimal:2',
            'nhia_split'            => 'decimal:2',
            'tier2_employer'        => 'decimal:2',
            'tier3_employee'        => 'decimal:2',
            'paye'                  => 'decimal:2',
            'voluntary_deductions'  => 'decimal:2',
            'net'                   => 'decimal:2',
            'overtime_hours'        => 'decimal:2',
            'overtime_pay'          => 'decimal:2',
            'breakdown'             => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function scopeCalculated(Builder $query): Builder
    {
        return $query->where('status', 'calculated');
    }

    public function scopeSkipped(Builder $query): Builder
    {
        return $query->where('status', 'skipped');
    }
}
