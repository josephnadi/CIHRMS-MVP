<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\MemberClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A billable item the institute can charge members for — Annual Member
 * Dues, Exam Fee, Graduation Fee, Library Card Replacement, etc.
 * Reusable across periods; the (member × product × period) row lives on
 * `fee_assignments`.
 *
 * `applies_to_classes` is a JSON array of `MemberClass` values. NULL
 * means "applies to every class"; an empty array means "applies to
 * none" (rarely useful, but explicit). The BillingRunService consults
 * this filter when deciding which members are eligible for a run.
 */
class FeeProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'amount',
        'currency',
        'billing_cycle',
        'applies_to_classes',
        'gl_income_account_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount'             => 'decimal:2',
            'billing_cycle'      => BillingCycle::class,
            'applies_to_classes' => 'array',
            'is_active'          => 'boolean',
        ];
    }

    public function incomeGl(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_income_account_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FeeAssignment::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Does this product apply to the given member class? NULL applies-to
     * means "all classes"; otherwise it must be in the JSON list.
     */
    public function appliesToClass(MemberClass $class): bool
    {
        $allowed = $this->applies_to_classes;
        if ($allowed === null) {
            return true;
        }
        if (! is_array($allowed)) {
            return false;
        }
        return in_array($class->value, $allowed, true);
    }
}
