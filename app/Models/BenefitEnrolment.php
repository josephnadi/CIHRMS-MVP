<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BenefitEnrolmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BenefitEnrolment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'plan_id', 'employee_id', 'enrolled_at',
        'effective_from', 'effective_to', 'status',
        'monthly_premium', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'          => BenefitEnrolmentStatus::class,
            'enrolled_at'     => 'date',
            'effective_from'  => 'date',
            'effective_to'    => 'date',
            'monthly_premium' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BenefitPlan::class, 'plan_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(BenefitClaim::class, 'enrolment_id');
    }

    public function scopeActive($q)
    {
        return $q->where('status', BenefitEnrolmentStatus::Active->value);
    }
}
