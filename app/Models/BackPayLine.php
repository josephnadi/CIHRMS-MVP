<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's arrears on a back-pay run — the accumulated deltas across every
 * affected month, with the per-month breakdown retained for the audit trail.
 */
class BackPayLine extends Model
{
    protected $fillable = [
        'back_pay_run_id', 'employee_id',
        'gross', 'arrears_net', 'back_paye',
        'ssnit_employee', 'ssnit_employer', 'tier2_employer', 'tier3_employee',
        'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'gross'          => 'decimal:2',
            'arrears_net'    => 'decimal:2',
            'back_paye'      => 'decimal:2',
            'ssnit_employee' => 'decimal:2',
            'ssnit_employer' => 'decimal:2',
            'tier2_employer' => 'decimal:2',
            'tier3_employee' => 'decimal:2',
            'breakdown'      => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BackPayRun::class, 'back_pay_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
