<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Clean up file uploads on hard delete. SoftDeletes keeps the files
     * during the soft-delete window (allows restore); forceDelete blasts
     * them. L4 audit fix — closes storage bloat + enumeration window.
     */
    protected static function booted(): void
    {
        // Fires BEFORE the DB row is removed so we can still walk the
        // documents relation. `forceDeleted` runs AFTER cascade delete and
        // would find the relation empty.
        static::forceDeleting(function (self $employee) {
            if ($employee->avatar_path) {
                Storage::disk('local')->delete($employee->avatar_path);
                Storage::disk('public')->delete($employee->avatar_path); // legacy
            }
            foreach ($employee->documents()->get() as $doc) {
                if ($doc->file_path) {
                    Storage::disk('local')->delete($doc->file_path);
                    Storage::disk('public')->delete($doc->file_path);
                }
            }
        });
    }

    protected $fillable = [
        'department_id',
        'user_id',
        'employee_no',
        'position',
        'hire_date',
        'phone',
        'status',
        // Personal
        'gender',
        'date_of_birth',
        'national_id',
        'ssnit_number',
        'tin_number',
        'address',
        'avatar_path',
        // Emergency
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        // Compensation / hierarchy
        'bank_name',
        'bank_account',
        'bank_sort_code',
        'salary',
        'manager_id',
        // Establishment / position
        'current_position_id',
        'current_grade_id',
        'current_step',
        'step_anniversary_date',
        // Pension
        'tier2_trustee_id',
        'tier3_rate',
        'tier3_trustee_id',
        // Disbursement preference (cash | bank_transfer | mobile_money | ghipss)
        'disbursement_channel',
        // Integrations
        'external_crm_id',
    ];

    protected function casts(): array
    {
        return [
            'hire_date'             => 'date',
            'date_of_birth'         => 'date',
            'step_anniversary_date' => 'date',
            'salary'                => 'decimal:2',
            'tier3_rate'            => 'decimal:4',
            'current_step'          => 'integer',
            'status'                => EmployeeStatus::class,
        ];
    }

    /**
     * Public-storage URL of the uploaded avatar (or null).
     *
     * Returns a root-relative path (e.g. `/storage/avatars/foo.webp`) instead
     * of `Storage::url()`'s absolute URL. Storage::url() bakes in APP_URL,
     * which in development is often `http://localhost` while the dev server
     * actually serves on `http://127.0.0.1:8000` — the browser then 404s
     * against plain `localhost`. A root-relative URL just uses the current
     * host, so it works in dev, prod, behind any reverse proxy, etc.
     */
    public function avatarUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->avatar_path) return null;
            // Avatars now live on the private `local` disk (H10 audit fix).
            // Mint a short-lived signed URL pointing at the streaming endpoint;
            // the public `/storage/` symlink no longer surfaces these files.
            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'employees.files.avatar',
                now()->addMinutes(15),
                ['employee' => $this->id],
            );
        });
    }

    /** Years of service as a fractional number for tenure displays. */
    public function tenureYears(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->hire_date) return null;
            return round($this->hire_date->floatDiffInYears(now()), 2);
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class);
    }

    public function currentPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'current_position_id');
    }

    public function currentGrade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'current_grade_id');
    }

    public function tier2Trustee(): BelongsTo
    {
        return $this->belongsTo(PensionTrustee::class, 'tier2_trustee_id');
    }

    public function tier3Trustee(): BelongsTo
    {
        return $this->belongsTo(PensionTrustee::class, 'tier3_trustee_id');
    }

    public function allowances(): HasMany
    {
        return $this->hasMany(Allowance::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(Deduction::class);
    }

    public function identityVerifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class);
    }

    public function positionAssignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class);
    }

    public function dependants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Dependant::class);
    }

    public function benefitEnrolments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\BenefitEnrolment::class);
    }

    public function offboardingCases(): HasMany
    {
        return $this->hasMany(OffboardingCase::class);
    }

    public function latestVerifiedIdentity(): ?IdentityVerification
    {
        return $this->identityVerifications()->usable()->latest('verified_at')->first();
    }

    public function hasUsableIdentity(): bool
    {
        return $this->latestVerifiedIdentity() !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EmployeeStatus::Active);
    }

    public function scopeInDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Restrict to records visible to the supplied user, applying RBAC scoping:
     *  - super_admin / hr_admin: all employees
     *  - dept_head / manager:   own department(s) + direct reports + self
     *  - everyone else:         only the employee row tied to their user
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) return $query->whereRaw('1=0');

        if ($user->isSuperAdmin() || $user->hasPermission('employees.manage')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            // Self
            $q->where('user_id', $user->id);

            // Departments they head/manage
            $managedIds = $user->managedDepartmentIds()->all();
            if (! empty($managedIds)) {
                $q->orWhereIn('department_id', $managedIds);
            }

            // Direct reports (where this user IS the manager via Employee record)
            if ($user->employee?->id) {
                $q->orWhere('manager_id', $user->employee->id);
            }
        });
    }
}
