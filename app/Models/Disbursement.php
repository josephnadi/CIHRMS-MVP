<?php

namespace App\Models;

use App\Enums\DisbursementChannel;
use App\Enums\DisbursementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Disbursement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payroll_run_id', 'payroll_line_id', 'employee_id', 'final_settlement_id',
        'channel', 'status',
        'gross_amount', 'e_levy', 'provider_fee', 'net_to_recipient',
        'beneficiary_account', 'beneficiary_name',
        'provider_reference', 'provider_response',
        'sent_at', 'settled_at', 'failed_at', 'failure_reason', 'retry_count',
        'payout_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'channel'           => DisbursementChannel::class,
            'status'            => DisbursementStatus::class,
            'gross_amount'      => 'decimal:2',
            'e_levy'            => 'decimal:2',
            'provider_fee'      => 'decimal:2',
            'net_to_recipient'  => 'decimal:2',
            'provider_response' => 'array',
            'sent_at'           => 'datetime',
            'settled_at'        => 'datetime',
            'failed_at'         => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function finalSettlement(): BelongsTo
    {
        return $this->belongsTo(FinalSettlement::class, 'final_settlement_id');
    }

    public function payoutBatch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class);
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', DisbursementStatus::Pending->value);
    }

    public function scopeUnsettled(Builder $q): Builder
    {
        return $q->whereIn('status', [DisbursementStatus::Pending->value, DisbursementStatus::Sent->value]);
    }
}
