<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A back-pay (arrears) run for a salary revision. Statuses:
 *   draft → approved (GL accrual posted) → paid.
 */
class BackPayRun extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID     = 'paid';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'reference', 'salary_revision_id', 'effective_from', 'status',
        'created_by', 'approved_by', 'reversed_by',
        'approved_at', 'paid_at', 'reversed_at', 'notes',
        'employees_count', 'gross_total', 'arrears_net_total', 'back_paye_total',
        'ssnit_employee_total', 'ssnit_employer_total', 'tier2_employer_total', 'tier3_employee_total',
    ];

    protected function casts(): array
    {
        return [
            'effective_from'       => 'date',
            'approved_at'          => 'datetime',
            'paid_at'              => 'datetime',
            'reversed_at'          => 'datetime',
            'gross_total'          => 'decimal:2',
            'arrears_net_total'    => 'decimal:2',
            'back_paye_total'      => 'decimal:2',
            'ssnit_employee_total' => 'decimal:2',
            'ssnit_employer_total' => 'decimal:2',
            'tier2_employer_total' => 'decimal:2',
            'tier3_employee_total' => 'decimal:2',
        ];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(SalaryRevision::class, 'salary_revision_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BackPayLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
