<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BenefitType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BenefitPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'type', 'provider', 'description',
        'monthly_cost', 'employee_contribution_percentage',
        'is_active', 'effective_from', 'effective_to',
        'max_dependants', 'cover_details',
    ];

    protected function casts(): array
    {
        return [
            'type'                              => BenefitType::class,
            'monthly_cost'                      => 'decimal:2',
            'employee_contribution_percentage'  => 'decimal:2',
            'is_active'                         => 'boolean',
            'effective_from'                    => 'date',
            'effective_to'                      => 'date',
            'max_dependants'                    => 'integer',
            'cover_details'                     => 'array',
        ];
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(BenefitEnrolment::class, 'plan_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
