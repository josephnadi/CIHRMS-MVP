<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComplianceTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceRequirement extends Model
{
    protected $fillable = ['course_id', 'name', 'target_type', 'target_value', 'due_in_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'target_type' => ComplianceTarget::class,
            'is_active'   => 'bool',
            'due_in_days' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class, 'requirement_id');
    }

    public function matches(Employee $employee): bool
    {
        return match ($this->target_type) {
            ComplianceTarget::AllStaff   => true,
            ComplianceTarget::Role       => $employee->user?->role?->value === $this->target_value,
            ComplianceTarget::Department => (int) $employee->department_id === (int) $this->target_value,
        };
    }

    /** Active employees this requirement targets. */
    public function matchingEmployees(): Builder
    {
        $query = Employee::query()->where('status', 'active');

        return match ($this->target_type) {
            ComplianceTarget::AllStaff   => $query,
            ComplianceTarget::Department => $query->where('department_id', (int) $this->target_value),
            ComplianceTarget::Role       => $query->whereHas('user', fn ($u) => $u->where('role', $this->target_value)),
        };
    }
}
