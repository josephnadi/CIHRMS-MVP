<?php

namespace App\Models;

use App\Enums\StatutoryReturnKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryReturn extends Model
{
    protected $fillable = [
        'payroll_run_id', 'kind', 'trustee_id', 'file_path',
        'total_amount', 'record_count',
        'generated_at', 'submitted_at', 'submitted_by',
        'submission_reference',
    ];

    protected function casts(): array
    {
        return [
            'kind'         => StatutoryReturnKind::class,
            'total_amount' => 'decimal:2',
            'record_count' => 'integer',
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function trustee(): BelongsTo
    {
        return $this->belongsTo(PensionTrustee::class, 'trustee_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
