<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'name', 'email', 'password', 'role', 'permissions', 'staff_id',
    // Wave 12 — messaging preferences
    'notification_channels', 'whatsapp_phone', 'whatsapp_consent_at', 'slack_user_id',
    // Phase 1 — TOTP 2FA
    'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
    'two_factor_required', 'two_factor_last_used_at',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Legacy fallback table — used for seeding and backwards-compat only.
     * The DB-backed `roles` + `role_permissions` tables are the source of truth.
     */
    public const ROLE_PERMISSIONS = [
        'super_admin' => ['*'],
        'hr_admin' => [
            'dashboard.view', 'employees.manage', 'employees.view',
            'leave.request', 'leave.manage', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'complaints.manage',
            'recruitment.manage', 'recruitment.apply',
            'integrations.manage',
            'performance.view', 'performance.manage',
            'learning.view', 'learning.manage',
            // HR sees every department portal for cross-functional oversight.
            'portal.hr', 'portal.it', 'portal.finance', 'portal.marketing',
        ],
        'manager' => [
            'dashboard.view', 'employees.view',
            'leave.request', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply', 'reports.view',
            'performance.view', 'performance.manage',
            'learning.view', 'learning.manage',
        ],
        'dept_head' => [
            'dashboard.view', 'employees.view',
            'leave.request', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply', 'reports.view',
            'performance.view', 'performance.manage',
            'learning.view', 'learning.manage',
        ],
        'employee' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'performance.view',
            'learning.view',
        ],
        'finance_officer' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'payroll.manage', 'reports.view',
            'learning.view',
            'portal.finance',
        ],
        'it_support' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'learning.view',
            'portal.it',
        ],
        'marketing' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'learning.view',
            'portal.marketing',
        ],
        'auditor' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'reports.view', 'audit.view',
            'learning.view',
        ],
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'password'                 => 'hashed',
            'permissions'              => 'array',
            'role'                     => UserRole::class,
            'notification_channels'    => 'array',
            'whatsapp_consent_at'      => 'datetime',
            'two_factor_confirmed_at'  => 'datetime',
            'two_factor_last_used_at'  => 'datetime',
            'two_factor_required'      => 'bool',
        ];
    }

    /**
     * Resolved messaging preferences with sensible defaults.
     *
     * @return array{email:bool,in_app:bool,whatsapp:bool,slack:bool,teams:bool}
     */
    public function notificationPreferences(): array
    {
        $prefs = (array) ($this->notification_channels ?? []);

        return [
            'email'    => (bool) ($prefs['email']    ?? true),
            'in_app'   => (bool) ($prefs['in_app']   ?? true),
            'whatsapp' => (bool) ($prefs['whatsapp'] ?? false), // opt-in only
            'slack'    => (bool) ($prefs['slack']    ?? false),
            'teams'    => (bool) ($prefs['teams']    ?? false),
        ];
    }

    public function hasWhatsappConsent(): bool
    {
        return $this->whatsapp_consent_at !== null && $this->whatsapp_phone !== null;
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** All roles assigned via the user_roles pivot. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('department_id')
            ->withTimestamps();
    }

    /** Departments this user heads (via departments.head_user_id). */
    public function headedDepartments(): HasMany
    {
        return $this->hasMany(Department::class, 'head_user_id');
    }

    /** All role slugs the user holds (legacy primary role + DB-assigned). */
    public function allRoleSlugs(): array
    {
        $primary  = $this->role instanceof UserRole ? $this->role->value : $this->role;
        $assigned = $this->roles->pluck('slug')->all();

        return array_values(array_unique(array_filter([$primary, ...$assigned])));
    }

    public function hasRole(array|string|UserRole $roles): bool
    {
        $needed = collect((array) $roles)->map(
            fn ($r) => $r instanceof UserRole ? $r->value : $r
        )->all();

        return (bool) array_intersect($needed, $this->allRoleSlugs());
    }

    /**
     * Resolve the full permission set for this user.
     * Combines DB-backed role permissions, the legacy primary-role lookup,
     * and any per-user custom permissions stored on the user row.
     */
    public function allPermissions(): array
    {
        $cacheKey = "user_perms_{$this->id}_{$this->updated_at?->timestamp}";

        return Cache::remember($cacheKey, 60, function () {
            $primaryRole = $this->role instanceof UserRole ? $this->role->value : $this->role;

            // 1. Legacy hardcoded mapping for the primary role (kept for back-compat
            //    while DB seeds catch up — same as the array source of truth).
            $legacy = self::ROLE_PERMISSIONS[$primaryRole] ?? [];

            // 2. DB-backed roles (relation eager-loaded if available).
            $db = $this->relationLoaded('roles')
                ? $this->roles->flatMap->permissions->pluck('slug')->all()
                : Permission::whereHas('roles', fn ($q) => $q->whereIn(
                    'roles.id',
                    $this->roles()->pluck('roles.id')
                ))->pluck('slug')->all();

            // 3. Per-user overrides JSON column.
            $custom = array_values(array_filter($this->permissions ?? []));

            return array_values(array_unique([...$legacy, ...$db, ...$custom]));
        });
    }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->allPermissions();

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SuperAdmin);
    }

    /**
     * Department IDs this user can act on as a head/manager
     * (via departments.head_user_id OR via dept-scoped role assignments).
     */
    public function managedDepartmentIds(): Collection
    {
        $headed = $this->headedDepartments()->pluck('id');
        $scoped = $this->roles()->wherePivotNotNull('department_id')->pluck('user_roles.department_id');

        return $headed->merge($scoped)->unique()->values();
    }

    public function managesDepartment(?int $departmentId): bool
    {
        if ($departmentId === null) return false;
        if ($this->isSuperAdmin())  return true;

        return $this->managedDepartmentIds()->contains($departmentId);
    }
}
