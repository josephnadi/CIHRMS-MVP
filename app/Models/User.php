<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'locale', 'password', 'role', 'permissions', 'staff_id',
    // Wave 12 — messaging preferences
    'notification_channels', 'whatsapp_phone', 'whatsapp_consent_at', 'slack_user_id',
    // Phase 1 — TOTP 2FA
    'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
    'two_factor_required', 'two_factor_last_used_at',
    // P6 — force first-login password change
    'password_must_change',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** Computed attributes that serialise to JSON / Inertia by default. */
    protected $appends = ['avatar'];

    /**
     * Legacy fallback table — mirror of \Database\Seeders\RolePermissionSeeder::ROLE_PERMS.
     *
     * Two paths read this map:
     *  • production code when the DB-backed `roles` relation isn't eager-loaded
     *  • test factories that create a User with `role` but don't attach DB roles
     *
     * Keep this list in lock-step with the seeder. If you grant a permission to
     * a role in the seeder, mirror it here — otherwise authorisation will drift
     * between fresh-seeded DBs and test/factory-only flows.
     */
    public const ROLE_PERMISSIONS = [
        'super_admin' => ['*'],
        'hr_admin' => [
            'dashboard.view', 'employees.view', 'employees.manage', 'employees.transfer',
            'employees.view_salary',
            'leave.request', 'leave.approve', 'leave.manage',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'complaints.manage',
            'recruitment.apply', 'recruitment.manage',
            'payroll.view', 'payroll.run', 'payroll.view_all',
            'positions.view', 'positions.manage', 'grades.manage',
            'identity.view', 'identity.verify',
            'attendance.view', 'attendance.manage', 'attendance.clock_self', 'attendance.shift_manage',
            'attendance.approve', 'attendance.correct',
            'loans.view', 'loans.apply', 'loans.manage', 'loans.product_manage',
            'offboarding.view', 'offboarding.initiate', 'offboarding.clear',
            'offboarding.settle', 'offboarding.manage',
            'performance.view', 'performance.manage', 'performance.calibrate', 'performance.pip_manage',
            'learning.view', 'learning.manage',
            'assets.view', 'assets.manage', 'assets.assign',
            'messaging.view', 'messaging.send', 'messaging.manage',
            'sso.manage', 'sso.audit_view',
            'benefits.view', 'benefits.view_all', 'benefits.manage', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.manage', 'governance.acknowledge', 'governance.cert_manage',
            'announcements.manage',
            'reports.view',
            'integrations.manage', 'users.manage',
            // HR sees every department portal for cross-functional oversight.
            'portal.hr', 'portal.it', 'portal.finance', 'portal.marketing',
            'portal.membership', 'portal.pcp', 'portal.cpd', 'portal.administration',
        ],
        'manager' => [
            'dashboard.view', 'employees.view',
            'leave.request', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'attendance.view', 'attendance.clock_self',
            'attendance.approve', 'attendance.correct',
            'performance.view', 'performance.manage',
            'learning.view', 'learning.manage',
            'assets.view', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            'reports.view',
        ],
        'dept_head' => [
            'dashboard.view', 'employees.view', 'employees.transfer',
            'leave.request', 'leave.approve',
            'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'positions.view',
            'attendance.view', 'attendance.clock_self',
            'attendance.approve', 'attendance.correct',
            'performance.view', 'performance.manage',
            'learning.view', 'learning.manage',
            'assets.view', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            'reports.view',
        ],
        'employee' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'attendance.clock_self', 'attendance.correct',
            'performance.view',
            'learning.view',
            'loans.apply',
            'assets.view',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
        ],
        'finance_officer' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'payroll.view', 'payroll.manage', 'payroll.approve', 'payroll.view_all', 'statutory.export',
            'employees.view_salary',
            'attendance.correct',
            'loans.view', 'loans.apply', 'loans.approve', 'loans.disburse',
            'payroll.disburse',
            'offboarding.view', 'offboarding.settle', 'offboarding.approve',
            'learning.view',
            'assets.view',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            'reports.view',
            'portal.finance',
            // F1 — Finance Hub & Chart of Accounts
            'accounts.view', 'accounts.manage',
            'bank_accounts.view', 'bank_accounts.manage',
            'finance.hub',
            // F2 — Accounts Payable & Journal
            'vendors.view', 'vendors.manage',
            'ap_invoices.view', 'ap_invoices.create', 'ap_invoices.approve', 'ap_invoices.pay',
            'journal.view',
            // F3 — Accounts Receivable
            'customers.view', 'customers.manage',
            'ar_invoices.view', 'ar_invoices.create', 'ar_invoices.approve',
            'ar_invoices.receive', 'ar_invoices.write_off',
            'statements.view',
            // F4 — Paystack Gateway (no refund — super_admin only)
            'gateway.view', 'gateway.create',
        ],
        'it_support' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'tickets.manage',
            'complaints.create', 'recruitment.apply',
            'attendance.correct',
            'learning.view',
            'assets.view', 'assets.manage', 'assets.assign',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            'portal.it',
        ],
        'marketing' => [
            'dashboard.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'learning.view',
            'portal.marketing',
        ],
        'auditor' => [
            'dashboard.view', 'employees.view',
            'leave.request', 'tickets.create', 'complaints.create', 'recruitment.apply',
            'reports.view', 'audit.view',
            'payroll.view_all', 'positions.view', 'identity.view', 'statutory.export',
            'attendance.view', 'attendance.correct',
            'learning.view',
            'assets.view',
            'whistleblower.view_all', 'whistleblower.investigate',
            'performance.calibrate_apply',
            'privacy.fulfill',
            'benefits.view', 'benefits.enrol', 'benefits.claim',
            'governance.view', 'governance.acknowledge',
            // F1 — Finance read-only oversight
            'accounts.view', 'bank_accounts.view',
            // F2 — Read-only oversight
            'vendors.view', 'ap_invoices.view', 'journal.view',
            // F3 — Read-only oversight
            'customers.view', 'ar_invoices.view', 'statements.view',
            // F4 — Read-only gateway oversight
            'gateway.view',
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
            'password_must_change'     => 'bool',
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

    /**
     * Public-storage URL of the linked employee's avatar (or null).
     * Surfaced to the Inertia layer as `auth.user.avatar` so the
     * sidebar + top-right pill can render the photo without an
     * extra round-trip.
     *
     * Returns null when `employee` isn't eager-loaded, instead of
     * triggering a lazy-load query. This is the difference between a
     * silent N+1 in dev and a 500 in production: `User` is appended
     * with `avatar` (see $appends), so every JSON-serialized User
     * fires this accessor — including User instances loaded via
     * partial column selects like `with('user:id,name')` on related
     * models (analytics events, employee.user, etc.), where the
     * employee relation is never going to be loaded. Letting the
     * accessor lazy-load there would either crash under strict mode
     * or fan out one query per row.
     *
     * The authenticated user's employee IS preloaded in
     * HandleInertiaRequests::share(), so `auth.user.avatar` is
     * unaffected.
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->relationLoaded('employee')) {
                return null;
            }
            return $this->employee?->avatar_url;
        })->shouldCache();
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
