<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClaimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BenefitClaim extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'enrolment_id', 'claim_reference', 'amount', 'currency',
        'claim_date', 'description', 'status',
        'submitted_at', 'decision_at', 'decision_notes', 'decided_by',
    ];

    protected function casts(): array
    {
        return [
            'status'       => ClaimStatus::class,
            'amount'       => 'decimal:2',
            'claim_date'   => 'date',
            'submitted_at' => 'datetime',
            'decision_at'  => 'datetime',
        ];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(BenefitEnrolment::class, 'enrolment_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
