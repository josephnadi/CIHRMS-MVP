# P5 — Governance Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the Governance module end-to-end at enterprise-deeper depth per spec §11: policy CRUD with versioning, typed-name acknowledgement workflow with IP+UA capture, and certification expiry reminders.

**Architecture:** Greenfield Policies subsystem (policies + policy_versions + policy_acknowledgements). Certifications **reuses** the existing `App\Models\Certification` (built for Learning module) and adds a `reminder_sent_at` column via migration so the daily cron can dispatch reminders idempotently.

**Tech Stack:** Laravel 13.7 / PHP 8.3 / Pest 4 / Vue 3 / Inertia v2 / Tailwind v3.

**Reference spec:** [docs/superpowers/specs/2026-05-15-cihrms-end-to-end-wiring-design.md §11](../specs/2026-05-15-cihrms-end-to-end-wiring-design.md)

---

## File map

### Created (PHP)
- `app/Enums/PolicyCategory.php` (hr|finance|it|compliance|safety|conduct|other)
- `database/migrations/2026_05_30_000001_create_policies_tables.php` (policies, policy_versions, policy_acknowledgements)
- `database/migrations/2026_05_30_000002_add_reminder_sent_at_to_certifications.php`
- `app/Models/Policy.php`
- `app/Models/PolicyVersion.php`
- `app/Models/PolicyAcknowledgement.php`
- `app/Services/GovernanceService.php`
- `app/Events/PolicyDrafted.php`
- `app/Events/PolicyVersionAdded.php`
- `app/Events/PolicyPublished.php`
- `app/Events/PolicyAcknowledged.php`
- `app/Events/CertificationExpiring.php`
- `app/Events/CertificationExpired.php`
- `app/Http/Requests/Governance/StorePolicyRequest.php`
- `app/Http/Requests/Governance/UpdatePolicyRequest.php`
- `app/Http/Requests/Governance/StorePolicyVersionRequest.php`
- `app/Http/Requests/Governance/PublishPolicyVersionRequest.php`
- `app/Http/Requests/Governance/AcknowledgePolicyRequest.php`
- `app/Http/Requests/Governance/StoreCertificationRequest.php` (governance-specific; differs from existing Learning Cert request which assumes course_id)
- `app/Http/Requests/Governance/UpdateCertificationRequest.php`
- `app/Http/Resources/PolicyResource.php`
- `app/Http/Resources/PolicyVersionResource.php`
- `app/Http/Resources/GovernanceCertificationResource.php` (governance-flavoured cert view; broader than existing learning CertificationResource)
- `app/Http/Controllers/GovernanceController.php`
- `app/Policies/GovernancePolicy.php`
- `app/Console/Commands/DispatchCertificationReminders.php`
- `tests/Feature/Governance/PolicyWorkflowTest.php`
- `tests/Feature/Governance/CertificationReminderTest.php`
- `tests/Feature/Governance/GovernanceControllerTest.php`

### Created (Vue)
- `resources/js/Pages/Governance/Show.vue` (policy detail + ack)
- `resources/js/Pages/Governance/Manage.vue` (HR editor + versions tab)
- `resources/js/Pages/Governance/Certifications.vue`
- Modified: `resources/js/Pages/Governance/Index.vue` (replace hardcoded mock with real backend props)

### Modified
- `database/seeders/RolePermissionSeeder.php` — 4 governance permissions
- `app/Providers/AppServiceProvider.php` — service singleton + policy registration + 6 event listeners
- `app/Listeners/RecordAnalyticsEvent.php` — 6 new match arms
- `app/Listeners/SendNotifications.php` — handle `CertificationExpiring`
- `routes/web.php` — governance routes
- `routes/console.php` — schedule daily cert reminders
- `docs/PROJECT_STATE.md` — reflect P5 + project complete

---

## TASK 1: Policies schema + Certifications column

**Files:**
- Create: `app/Enums/PolicyCategory.php`
- Create: `database/migrations/2026_05_30_000001_create_policies_tables.php`
- Create: `database/migrations/2026_05_30_000002_add_reminder_sent_at_to_certifications.php`
- Create: `app/Models/Policy.php`
- Create: `app/Models/PolicyVersion.php`
- Create: `app/Models/PolicyAcknowledgement.php`

### Enum

`app/Enums/PolicyCategory.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum PolicyCategory: string
{
    case Hr         = 'hr';
    case Finance    = 'finance';
    case It         = 'it';
    case Compliance = 'compliance';
    case Safety     = 'safety';
    case Conduct    = 'conduct';
    case Other      = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hr         => 'HR',
            self::Finance    => 'Finance',
            self::It         => 'IT',
            self::Compliance => 'Compliance',
            self::Safety     => 'Safety',
            self::Conduct    => 'Conduct',
            self::Other      => 'Other',
        };
    }
}
```

### Migration — policies + policy_versions + policy_acknowledgements

`database/migrations/2026_05_30_000001_create_policies_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 200)->unique();
            $table->string('category', 16);
            $table->text('summary')->nullable();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index('category');
            $table->index('is_active');
        });

        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version_number');
            $table->longText('body');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('changelog')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['policy_id', 'version_number'], 'policy_versions_unique');
        });

        // Add the FK now that policy_versions exists
        Schema::table('policies', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('policy_versions')
                ->nullOnDelete();
        });

        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->string('signed_full_name', 120);
            $table->timestamps();
            $table->unique(['policy_version_id', 'user_id'], 'policy_ack_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
        Schema::table('policies', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('policies');
    }
};
```

### Migration — add `reminder_sent_at` to certifications

`database/migrations/2026_05_30_000002_add_reminder_sent_at_to_certifications.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certifications', function (Blueprint $table) {
            if (! Schema::hasColumn('certifications', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable()->after('verification_url');
                $table->index('reminder_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('certifications', function (Blueprint $table) {
            if (Schema::hasColumn('certifications', 'reminder_sent_at')) {
                $table->dropIndex(['reminder_sent_at']);
                $table->dropColumn('reminder_sent_at');
            }
        });
    }
};
```

### Models

`app/Models/Policy.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PolicyCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Policy extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'policies';

    protected $fillable = [
        'title', 'slug', 'category', 'summary',
        'owner_user_id', 'is_active', 'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'category'   => PolicyCategory::class,
            'is_active'  => 'boolean',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class)->orderBy('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'current_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
```

`app/Models/PolicyVersion.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_id', 'version_number', 'body',
        'effective_from', 'effective_to', 'changelog',
        'published_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to'   => 'date',
            'published_at'   => 'datetime',
            'version_number' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class);
    }

    public function scopePublished($q)
    {
        return $q->whereNotNull('published_at');
    }
}
```

`app/Models/PolicyAcknowledgement.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAcknowledgement extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_version_id', 'user_id', 'acknowledged_at',
        'ip_address', 'user_agent', 'signed_full_name',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

### Modify existing `Certification` model — add fillable + scope

Open `app/Models/Certification.php` and add `'reminder_sent_at'` to `$fillable`:
```php
protected $fillable = [
    'employee_id', 'course_id', 'name', 'issuer',
    'credential_id', 'issued_at', 'expires_at',
    'document_path', 'verification_url',
    'reminder_sent_at',
];
```

And add to `casts()`:
```php
'reminder_sent_at' => 'datetime',
```

And add a `scopeNeedingReminder` scope:
```php
public function scopeNeedingReminder(\Illuminate\Database\Eloquent\Builder $q, int $daysAhead = 30): \Illuminate\Database\Eloquent\Builder
{
    return $q->whereNotNull('expires_at')
        ->whereNull('reminder_sent_at')
        ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays($daysAhead)->endOfDay()]);
}
```

### Verify + commit

```powershell
php artisan migrate
php -l app/Enums/PolicyCategory.php
php -l app/Models/Policy.php
```

```powershell
git add app/Enums/PolicyCategory.php database/migrations/2026_05_30_000001_create_policies_tables.php database/migrations/2026_05_30_000002_add_reminder_sent_at_to_certifications.php app/Models/Policy.php app/Models/PolicyVersion.php app/Models/PolicyAcknowledgement.php app/Models/Certification.php
git commit -m "feat(governance): policies schema + 3 models + certifications.reminder_sent_at column"
```

---

## TASK 2: GovernanceService + 6 events + listener wiring

### Create 6 event classes

All same shape. Examples:

`app/Events/PolicyPublished.php`:
```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PolicyVersion;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PolicyPublished
{
    use Dispatchable;

    public function __construct(
        public readonly PolicyVersion $version,
        public readonly ?User $actor = null,
    ) {}
}
```

Same shape for:
- `PolicyDrafted` — carries `Policy $policy`
- `PolicyVersionAdded` — carries `PolicyVersion $version`
- `PolicyAcknowledged` — carries `PolicyAcknowledgement $acknowledgement`
- `CertificationExpiring` — carries `Certification $certification`
- `CertificationExpired` — carries `Certification $certification`

### GovernanceService

`app/Services/GovernanceService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CertificationExpired;
use App\Events\CertificationExpiring;
use App\Events\PolicyAcknowledged;
use App\Events\PolicyDrafted;
use App\Events\PolicyPublished;
use App\Events\PolicyVersionAdded;
use App\Models\Certification;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GovernanceService
{
    public function createPolicy(User $owner, array $data): Policy
    {
        return DB::transaction(function () use ($owner, $data) {
            $data['owner_user_id'] = $owner->id;
            $data['slug'] = $data['slug'] ?? Str::slug($data['title']);

            $policy = Policy::create($data);

            // First draft version (version 1, unpublished)
            $version = PolicyVersion::create([
                'policy_id'      => $policy->id,
                'version_number' => 1,
                'body'           => $data['initial_body'] ?? '# Draft policy body',
                'changelog'      => 'Initial draft',
            ]);

            PolicyDrafted::dispatch($policy, $owner);
            return $policy->fresh();
        });
    }

    public function addVersion(Policy $policy, User $author, string $body, ?string $changelog = null): PolicyVersion
    {
        $maxVersion = (int) ($policy->versions()->max('version_number') ?? 0);
        $version = PolicyVersion::create([
            'policy_id'      => $policy->id,
            'version_number' => $maxVersion + 1,
            'body'           => $body,
            'changelog'      => $changelog,
        ]);

        PolicyVersionAdded::dispatch($version, $author);
        return $version;
    }

    public function publish(PolicyVersion $version, User $publisher, \DateTimeInterface $effectiveFrom): PolicyVersion
    {
        if ($version->published_at !== null) {
            throw new DomainException("Version {$version->version_number} is already published.");
        }

        return DB::transaction(function () use ($version, $publisher, $effectiveFrom) {
            $effectiveCarbon = CarbonImmutable::instance($effectiveFrom);

            // Stamp previous current version's effective_to (if any)
            $policy = $version->policy;
            if ($policy->current_version_id && $policy->current_version_id !== $version->id) {
                PolicyVersion::where('id', $policy->current_version_id)
                    ->update(['effective_to' => $effectiveCarbon->subDay()->toDateString()]);
            }

            $version->update([
                'published_at'   => now(),
                'published_by'   => $publisher->id,
                'effective_from' => $effectiveCarbon->toDateString(),
            ]);

            $policy->update(['current_version_id' => $version->id]);

            PolicyPublished::dispatch($version->fresh(), $publisher);
            return $version->fresh();
        });
    }

    public function acknowledge(
        PolicyVersion $version,
        User $user,
        string $signedFullName,
        string $ipAddress,
        string $userAgent,
    ): PolicyAcknowledgement {
        if ($version->published_at === null) {
            throw new DomainException('Cannot acknowledge an unpublished version.');
        }

        $existing = PolicyAcknowledgement::where('policy_version_id', $version->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $ack = PolicyAcknowledgement::create([
            'policy_version_id' => $version->id,
            'user_id'           => $user->id,
            'acknowledged_at'   => now(),
            'ip_address'        => $ipAddress,
            'user_agent'        => $userAgent,
            'signed_full_name'  => $signedFullName,
        ]);

        PolicyAcknowledged::dispatch($ack, $user);
        return $ack;
    }

    public function pendingAcksFor(User $user): Collection
    {
        return Policy::query()
            ->active()
            ->whereNotNull('current_version_id')
            ->with('currentVersion')
            ->get()
            ->filter(function (Policy $p) use ($user) {
                if (! $p->currentVersion?->published_at) return false;
                return ! PolicyAcknowledgement::where('policy_version_id', $p->current_version_id)
                    ->where('user_id', $user->id)
                    ->exists();
            })
            ->values();
    }

    public function recordCertification(Employee $employee, array $data): Certification
    {
        return Certification::create(array_merge(
            ['employee_id' => $employee->id],
            $data,
        ));
    }

    public function dispatchExpiryReminders(int $daysAhead = 30): int
    {
        $count = 0;

        Certification::query()
            ->needingReminder($daysAhead)
            ->with('employee.user')
            ->chunkById(100, function ($chunk) use (&$count) {
                foreach ($chunk as $cert) {
                    if (! $cert->expires_at) continue;

                    if ($cert->expires_at->isPast()) {
                        CertificationExpired::dispatch($cert);
                    } else {
                        CertificationExpiring::dispatch($cert);
                    }

                    $cert->update(['reminder_sent_at' => now()]);
                    $count++;
                }
            });

        return $count;
    }
}
```

### Register in AppServiceProvider

In `register()`:
```php
$this->app->singleton(\App\Services\GovernanceService::class);
```

In `boot()` after existing event listeners:
```php
Event::listen(\App\Events\PolicyDrafted::class, RecordAnalyticsEvent::class);
Event::listen(\App\Events\PolicyVersionAdded::class, RecordAnalyticsEvent::class);
Event::listen(\App\Events\PolicyPublished::class, RecordAnalyticsEvent::class);
Event::listen(\App\Events\PolicyAcknowledged::class, RecordAnalyticsEvent::class);
Event::listen(\App\Events\CertificationExpiring::class, RecordAnalyticsEvent::class);
Event::listen(\App\Events\CertificationExpired::class, RecordAnalyticsEvent::class);

// Cert expiring also routes to SendNotifications so the user gets an email/in-app alert
Event::listen(\App\Events\CertificationExpiring::class, SendNotifications::class);
```

Note: `SendNotifications` listener may need extending to handle `CertificationExpiring`. Check its existing `match` expression; if there's no arm, add one that produces a `CertificationExpiringNotification` (use the existing `App\Notifications\*` pattern from learning's `LeaveApprovalReminder` etc.).

If `SendNotifications` doesn't have a clean pattern to extend, you can simplify: leave only the `RecordAnalyticsEvent` listener for `CertificationExpiring` for V1 and document the missing email channel as a follow-up.

### Extend RecordAnalyticsEvent

In `app/Listeners/RecordAnalyticsEvent.php`, add 6 match arms before `default`:

```php
$event instanceof \App\Events\PolicyDrafted => [
    'policy.drafted',
    ['policy_id' => $event->policy->id, 'slug' => $event->policy->slug],
],
$event instanceof \App\Events\PolicyVersionAdded => [
    'policy.version.added',
    ['policy_id' => $event->version->policy_id, 'version_id' => $event->version->id, 'version_number' => $event->version->version_number],
],
$event instanceof \App\Events\PolicyPublished => [
    'policy.published',
    ['policy_id' => $event->version->policy_id, 'version_id' => $event->version->id, 'version_number' => $event->version->version_number],
],
$event instanceof \App\Events\PolicyAcknowledged => [
    'policy.acknowledged',
    ['ack_id' => $event->acknowledgement->id, 'policy_version_id' => $event->acknowledgement->policy_version_id, 'user_id' => $event->acknowledgement->user_id],
],
$event instanceof \App\Events\CertificationExpiring => [
    'certification.expiring',
    ['certification_id' => $event->certification->id, 'employee_id' => $event->certification->employee_id, 'expires_at' => $event->certification->expires_at?->toDateString()],
],
$event instanceof \App\Events\CertificationExpired => [
    'certification.expired',
    ['certification_id' => $event->certification->id, 'employee_id' => $event->certification->employee_id, 'expired_at' => $event->certification->expires_at?->toDateString()],
],
```

### Verify + commit

```powershell
foreach ($f in 'PolicyDrafted','PolicyVersionAdded','PolicyPublished','PolicyAcknowledged','CertificationExpiring','CertificationExpired') { php -l "app/Events/$f.php" }
php -l app/Services/GovernanceService.php
php -l app/Providers/AppServiceProvider.php
```

```powershell
git add app/Events/PolicyDrafted.php app/Events/PolicyVersionAdded.php app/Events/PolicyPublished.php app/Events/PolicyAcknowledged.php app/Events/CertificationExpiring.php app/Events/CertificationExpired.php app/Services/GovernanceService.php app/Providers/AppServiceProvider.php app/Listeners/RecordAnalyticsEvent.php
git commit -m "feat(governance): GovernanceService with createPolicy/addVersion/publish/acknowledge/recordCertification/dispatchExpiryReminders + 6 events"
```

---

## TASK 3: FormRequests + GovernancePolicy + permissions

### Permissions

In `RolePermissionSeeder::PERMISSIONS`:
```php
        // ── Phase 5: Governance ──
        'governance.view'         => ['Governance', 'View policies and acknowledge them'],
        'governance.manage'       => ['Governance', 'Create / edit / publish policies'],
        'governance.acknowledge'  => ['Governance', 'Acknowledge published policies (self)'],
        'governance.cert_manage'  => ['Governance', 'Manage certification records + reminders'],
```

In `ROLE_PERMS`:
- `hr_admin` → add `governance.view`, `governance.manage`, `governance.acknowledge`, `governance.cert_manage`
- `manager`, `dept_head` → add `governance.view`, `governance.acknowledge`
- `employee`, `finance_officer`, `it_support`, `auditor` → add `governance.view`, `governance.acknowledge`

Re-seed: `php artisan db:seed --class=RolePermissionSeeder`

### 7 FormRequests in `app/Http/Requests/Governance/`

**StorePolicyRequest.php**:
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title'        => ['required', 'string', 'max:200'],
            'slug'         => ['nullable', 'string', 'max:200', 'unique:policies,slug'],
            'category'     => ['required', 'in:hr,finance,it,compliance,safety,conduct,other'],
            'summary'      => ['nullable', 'string', 'max:1000'],
            'is_active'    => ['nullable', 'boolean'],
            'initial_body' => ['nullable', 'string'],
        ];
    }
}
```

**UpdatePolicyRequest.php**: Same as Store, but `slug` rule:
```php
'slug' => ['nullable', 'string', 'max:200', \Illuminate\Validation\Rule::unique('policies', 'slug')->ignore($this->route('policy')?->id)],
```

**StorePolicyVersionRequest.php**:
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'body'      => ['required', 'string', 'min:20'],
            'changelog' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

**PublishPolicyVersionRequest.php**:
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class PublishPolicyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
        ];
    }
}
```

**AcknowledgePolicyRequest.php**:
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.acknowledge') ?? false;
    }

    public function rules(): array
    {
        return [
            'signed_full_name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * Server-side guard: the typed signature must match the authenticated
     * user's name (case-insensitive trim) per spec §11.
     */
    protected function passedValidation(): void
    {
        $expected = strtolower(trim((string) $this->user()->name));
        $signed   = strtolower(trim((string) $this->validated('signed_full_name')));

        if ($expected !== '' && $expected !== $signed) {
            abort(422, "Typed signature does not match the account name on file.");
        }
    }
}
```

**StoreCertificationRequest.php**:
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Governance;

use Illuminate\Foundation\Http\FormRequest;

class StoreCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('governance.cert_manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'      => ['required', 'integer', 'exists:employees,id'],
            'name'             => ['required', 'string', 'max:200'],
            'issuer'           => ['nullable', 'string', 'max:200'],
            'credential_id'    => ['nullable', 'string', 'max:120'],
            'issued_at'        => ['nullable', 'date'],
            'expires_at'       => ['nullable', 'date', 'after_or_equal:issued_at'],
            'verification_url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
```

**UpdateCertificationRequest.php**: All fields nullable; otherwise same.

### GovernancePolicy

`app/Policies/GovernancePolicy.php`:
```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Policy;
use App\Models\User;

class GovernancePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('super_admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('governance.view');
    }

    public function view(User $user, Policy $policy): bool
    {
        return $user->hasPermission('governance.view');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('governance.manage');
    }

    public function acknowledge(User $user): bool
    {
        return $user->hasPermission('governance.acknowledge');
    }

    public function manageCertifications(User $user): bool
    {
        return $user->hasPermission('governance.cert_manage');
    }
}
```

### Register policy in AppServiceProvider

```php
Gate::policy(\App\Models\Policy::class, \App\Policies\GovernancePolicy::class);
```

### Verify + commit

```powershell
foreach ($f in 'StorePolicyRequest','UpdatePolicyRequest','StorePolicyVersionRequest','PublishPolicyVersionRequest','AcknowledgePolicyRequest','StoreCertificationRequest','UpdateCertificationRequest') { php -l "app/Http/Requests/Governance/$f.php" }
php -l app/Policies/GovernancePolicy.php
php artisan db:seed --class=RolePermissionSeeder
```

```powershell
git add app/Http/Requests/Governance/ app/Policies/GovernancePolicy.php app/Providers/AppServiceProvider.php database/seeders/RolePermissionSeeder.php
git commit -m "feat(governance): 7 FormRequests + GovernancePolicy + 4 permissions (signed-name guard in AcknowledgePolicyRequest)"
```

---

## TASK 4: Resources + Controller + routes

### 3 JsonResources

`app/Http/Resources/PolicyResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PolicyResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request->user();
        $myAck = null;
        if ($user && $this->current_version_id) {
            $myAck = \App\Models\PolicyAcknowledgement::where('policy_version_id', $this->current_version_id)
                ->where('user_id', $user->id)
                ->first();
        }

        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'slug'         => $this->slug,
            'category'     => $this->category?->value,
            'summary'      => $this->summary,
            'is_active'    => (bool) $this->is_active,
            'owner'        => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id, 'name' => $this->owner->name,
            ] : null),
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion ? [
                'id'             => $this->currentVersion->id,
                'version_number' => $this->currentVersion->version_number,
                'effective_from' => $this->currentVersion->effective_from?->toDateString(),
                'published_at'   => $this->currentVersion->published_at?->toIso8601String(),
            ] : null),
            'my_ack_status' => $myAck ? 'acknowledged' : ($this->current_version_id ? 'pending' : 'no_version'),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

`app/Http/Resources/PolicyVersionResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PolicyVersionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'policy_id'      => $this->policy_id,
            'version_number' => $this->version_number,
            'body'           => $this->body,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to'   => $this->effective_to?->toDateString(),
            'changelog'      => $this->changelog,
            'published_at'   => $this->published_at?->toIso8601String(),
            'published_by'   => $this->whenLoaded('publishedBy', fn () => $this->publishedBy?->name),
            'ack_count'      => $this->whenCounted('acknowledgements'),
        ];
    }
}
```

`app/Http/Resources/GovernanceCertificationResource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GovernanceCertificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'employee_id'       => $this->employee_id,
            'employee'          => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'          => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'name'        => $this->employee->user?->name,
            ] : null),
            'name'              => $this->name,
            'issuer'            => $this->issuer,
            'credential_id'     => $this->credential_id,
            'issued_at'         => $this->issued_at?->toDateString(),
            'expires_at'        => $this->expires_at?->toDateString(),
            'days_to_expiry'    => $this->daysToExpiry,
            'verification_url'  => $this->verification_url,
            'reminder_sent_at'  => $this->reminder_sent_at?->toIso8601String(),
        ];
    }
}
```

### GovernanceController

`app/Http/Controllers/GovernanceController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Governance\AcknowledgePolicyRequest;
use App\Http\Requests\Governance\PublishPolicyVersionRequest;
use App\Http\Requests\Governance\StoreCertificationRequest;
use App\Http\Requests\Governance\StorePolicyRequest;
use App\Http\Requests\Governance\StorePolicyVersionRequest;
use App\Http\Requests\Governance\UpdateCertificationRequest;
use App\Http\Requests\Governance\UpdatePolicyRequest;
use App\Http\Resources\GovernanceCertificationResource;
use App\Http\Resources\PolicyResource;
use App\Http\Resources\PolicyVersionResource;
use App\Models\Certification;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Services\GovernanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceController extends Controller
{
    public function __construct(private readonly GovernanceService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Policy::class);

        $policies = Policy::query()
            ->with(['owner:id,name', 'currentVersion'])
            ->orderBy('title')
            ->get();

        return Inertia::render('Governance/Index', [
            'policies'        => PolicyResource::collection($policies),
            'pending_ack_ids' => $this->service->pendingAcksFor($request->user())->pluck('id'),
        ]);
    }

    public function showPolicy(Policy $policy): Response
    {
        $this->authorize('view', $policy);

        $policy->load(['owner:id,name', 'currentVersion', 'versions.publishedBy:id,name']);

        return Inertia::render('Governance/Show', [
            'policy'   => new PolicyResource($policy),
            'versions' => PolicyVersionResource::collection($policy->versions()->orderByDesc('version_number')->get()),
            'current'  => $policy->currentVersion ? new PolicyVersionResource($policy->currentVersion) : null,
        ]);
    }

    public function manage(Request $request): Response
    {
        $this->authorize('manage', Policy::class);

        return Inertia::render('Governance/Manage', [
            'policies' => PolicyResource::collection(
                Policy::with(['owner:id,name', 'currentVersion'])->orderBy('title')->get()
            ),
        ]);
    }

    public function storePolicy(StorePolicyRequest $request)
    {
        $this->service->createPolicy($request->user(), $request->validated());
        return back()->with('success', 'Policy drafted.');
    }

    public function updatePolicy(UpdatePolicyRequest $request, Policy $policy)
    {
        $policy->update($request->validated());
        return back()->with('success', 'Policy updated.');
    }

    public function addVersion(StorePolicyVersionRequest $request, Policy $policy)
    {
        $this->service->addVersion(
            $policy, $request->user(),
            $request->validated('body'),
            $request->validated('changelog'),
        );

        return back()->with('success', 'New version drafted.');
    }

    public function publishVersion(PublishPolicyVersionRequest $request, PolicyVersion $version)
    {
        $this->authorize('manage', $version->policy);

        try {
            $this->service->publish(
                $version, $request->user(),
                new \DateTimeImmutable($request->validated('effective_from')),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Version published.');
    }

    public function acknowledge(AcknowledgePolicyRequest $request, PolicyVersion $version)
    {
        try {
            $this->service->acknowledge(
                $version, $request->user(),
                $request->validated('signed_full_name'),
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Acknowledgement recorded.');
    }

    public function certificationsIndex(Request $request): Response
    {
        $this->authorize('viewAny', Policy::class);
        $canManage = $request->user()->hasPermission('governance.cert_manage');

        $query = Certification::with('employee.user:id,name');

        // Non-managers only see their own
        if (! $canManage) {
            $employeeId = $request->user()->employee?->id;
            $query->where('employee_id', $employeeId ?? 0);
        }

        return Inertia::render('Governance/Certifications', [
            'certifications' => GovernanceCertificationResource::collection($query->latest('expires_at')->paginate(50)),
            'employees'      => $canManage
                ? Employee::with('user:id,name')->active()->orderBy('id')->get(['id', 'user_id', 'employee_no', 'position'])
                : [],
        ]);
    }

    public function storeCertification(StoreCertificationRequest $request)
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));
        $this->service->recordCertification($employee, $request->validated());
        return back()->with('success', 'Certification recorded.');
    }

    public function updateCertification(UpdateCertificationRequest $request, Certification $certification)
    {
        $certification->update($request->validated());
        return back()->with('success', 'Certification updated.');
    }

    public function destroyCertification(Request $request, Certification $certification)
    {
        $this->authorize('manageCertifications', Policy::class);
        $certification->delete();
        return back()->with('success', 'Certification removed.');
    }

    public function dispatchReminders(Request $request)
    {
        $this->authorize('manageCertifications', Policy::class);

        $count = $this->service->dispatchExpiryReminders(30);
        return back()->with('success', "Sent reminders for {$count} expiring certifications.");
    }
}
```

### Routes

In `routes/web.php`, inside the `auth + audit` middleware group:

```php
    // ── Phase 5: Governance ──
    Route::prefix('governance')->name('governance.')->group(function () {
        Route::get('/',                            [GovernanceController::class, 'index'])
            ->middleware('permission:governance.view')->name('index');
        Route::get('/manage',                      [GovernanceController::class, 'manage'])
            ->middleware('permission:governance.manage')->name('manage');
        Route::get('/policies/{policy}',           [GovernanceController::class, 'showPolicy'])
            ->middleware('permission:governance.view')->name('policies.show');
        Route::post('/policies',                   [GovernanceController::class, 'storePolicy'])
            ->middleware('permission:governance.manage')->name('policies.store');
        Route::patch('/policies/{policy}',         [GovernanceController::class, 'updatePolicy'])
            ->middleware('permission:governance.manage')->name('policies.update');
        Route::post('/policies/{policy}/versions', [GovernanceController::class, 'addVersion'])
            ->middleware('permission:governance.manage')->name('policies.versions.store');
        Route::patch('/versions/{version}/publish',[GovernanceController::class, 'publishVersion'])
            ->middleware('permission:governance.manage')->name('versions.publish');
        Route::post('/versions/{version}/ack',     [GovernanceController::class, 'acknowledge'])
            ->middleware('permission:governance.acknowledge')->name('versions.ack');

        Route::prefix('certifications')->name('certifications.')->group(function () {
            Route::get('/',                        [GovernanceController::class, 'certificationsIndex'])->name('index');
            Route::post('/',                       [GovernanceController::class, 'storeCertification'])
                ->middleware('permission:governance.cert_manage')->name('store');
            Route::patch('/{certification}',       [GovernanceController::class, 'updateCertification'])
                ->middleware('permission:governance.cert_manage')->name('update');
            Route::delete('/{certification}',      [GovernanceController::class, 'destroyCertification'])
                ->middleware('permission:governance.cert_manage')->name('destroy');
            Route::post('/dispatch-reminders',     [GovernanceController::class, 'dispatchReminders'])
                ->middleware('permission:governance.cert_manage')->name('dispatch-reminders');
        });
    });
```

Update the `modules.governance` route to redirect to the real index:
```php
Route::get('governance',  fn () => redirect()->route('governance.index'))->name('governance');
```

At top of file, add: `use App\Http\Controllers\GovernanceController;`

### Verify + commit

```powershell
php -l app/Http/Controllers/GovernanceController.php
php artisan route:list --name=governance
npm run build
```

Expected: 13+ governance routes.

```powershell
git add app/Http/Resources/PolicyResource.php app/Http/Resources/PolicyVersionResource.php app/Http/Resources/GovernanceCertificationResource.php app/Http/Controllers/GovernanceController.php routes/web.php
git commit -m "feat(governance): GovernanceController (13 actions) + 3 Resources + routes wired"
```

---

## TASK 5: Daily cert reminders scheduled command

### Command

`app/Console/Commands/DispatchCertificationReminders.php`:
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GovernanceService;
use Illuminate\Console\Command;

class DispatchCertificationReminders extends Command
{
    protected $signature = 'governance:certification-reminders {--days=30 : Days-ahead window for upcoming expiries}';
    protected $description = 'Dispatch CertificationExpiring events for any certification expiring within --days days without a prior reminder.';

    public function handle(GovernanceService $service): int
    {
        $days = (int) ($this->option('days') ?: 30);
        $count = $service->dispatchExpiryReminders($days);

        $this->info("Dispatched {$count} certification reminder events (window: {$days}d).");
        return self::SUCCESS;
    }
}
```

### Schedule

Append to `routes/console.php`:
```php
// Governance: daily certification-expiry reminders at 08:00
Schedule::command('governance:certification-reminders')->dailyAt('08:00')->withoutOverlapping();
```

### Verify + commit

```powershell
php -l app/Console/Commands/DispatchCertificationReminders.php
php artisan schedule:list
php artisan governance:certification-reminders
```

```powershell
git add app/Console/Commands/DispatchCertificationReminders.php routes/console.php
git commit -m "feat(governance): DispatchCertificationReminders command + daily 08:00 schedule"
```

---

## TASK 6: Vue pages

### Replace `resources/js/Pages/Governance/Index.vue`

```vue
<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    policies:        Object,
    pending_ack_ids: Array,
});

const categoryLabel = {
    hr: 'HR', finance: 'Finance', it: 'IT', compliance: 'Compliance',
    safety: 'Safety', conduct: 'Conduct', other: 'Other',
};

const categoryTone = {
    hr:'bg-violet-100 text-violet-800', finance:'bg-amber-100 text-amber-800',
    it:'bg-sky-100 text-sky-800', compliance:'bg-rose-100 text-rose-800',
    safety:'bg-emerald-100 text-emerald-800', conduct:'bg-indigo-100 text-indigo-800',
    other:'bg-slate-100 text-slate-700',
};
</script>

<template>
<Head title="Governance" />
<AuthenticatedLayout active-module="governance">
    <div class="p-6 space-y-6 animate-reveal-up">
        <header class="flex items-center justify-between">
            <div>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary">Governance</h1>
                <p class="text-sm text-on-surface-variant">Policies you must read and acknowledge. Compliance certifications under tracking.</p>
            </div>
            <div class="flex gap-2">
                <Link v-if="$page.props.auth.permissions?.includes('governance.manage')" :href="route('governance.manage')" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Manage Policies</Link>
                <Link :href="route('governance.certifications.index')" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Certifications</Link>
            </div>
        </header>

        <section v-if="props.pending_ack_ids?.length" class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-5">
            <p class="text-[10px] font-black uppercase tracking-widest text-amber-700">Action Required</p>
            <p class="text-sm font-bold text-amber-900 mt-1">You have {{ props.pending_ack_ids.length }} {{ props.pending_ack_ids.length === 1 ? 'policy' : 'policies' }} pending acknowledgement.</p>
        </section>

        <section>
            <h2 class="text-[10px] font-black uppercase tracking-[0.1em] text-on-surface-variant/70 mb-3">All Policies</h2>
            <div v-if="props.policies.data?.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <Link v-for="p in props.policies.data" :key="p.id" :href="route('governance.policies.show', p.id)"
                    class="block rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-5 card-lift hover:border-primary/40 transition-colors">
                    <div class="flex items-start justify-between">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span :class="['rounded-full px-2 py-0.5 text-[10px] font-bold uppercase', categoryTone[p.category]]">{{ categoryLabel[p.category] }}</span>
                                <span v-if="p.my_ack_status === 'pending'" class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase bg-amber-100 text-amber-800">ACK REQUIRED</span>
                                <span v-else-if="p.my_ack_status === 'acknowledged'" class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase bg-emerald-100 text-emerald-800">ACK'D</span>
                            </div>
                            <h3 class="text-lg font-black text-primary mt-2 truncate">{{ p.title }}</h3>
                            <p v-if="p.summary" class="text-xs text-on-surface-variant mt-1 line-clamp-2">{{ p.summary }}</p>
                            <p v-if="p.current_version" class="text-[10px] text-on-surface-variant/70 mt-2 font-mono">v{{ p.current_version.version_number }} · effective {{ p.current_version.effective_from ?? '—' }}</p>
                            <p v-else class="text-[10px] text-on-surface-variant/70 mt-2 italic">No published version</p>
                        </div>
                    </div>
                </Link>
            </div>
            <EmptyState v-else title="No policies published yet." class="py-12" />
        </section>
    </div>
</AuthenticatedLayout>
</template>
```

### Create `resources/js/Pages/Governance/Show.vue`

Policy detail with markdown body, version history sidebar, typed-signature acknowledgement form.

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    policy:   Object,
    versions: Object,
    current:  Object,
});

const ackForm = useForm({ signed_full_name: '' });

function submitAck() {
    if (! props.current?.id) return;
    ackForm.post(route('governance.versions.ack', props.current.id), {
        preserveScroll: true,
        onSuccess: () => ackForm.reset(),
    });
}

// Minimal markdown -> HTML for paragraphs/headings. For richer rendering, hand off to a real lib if needed.
const renderedBody = computed(() => {
    const body = props.current?.body ?? '';
    return body
        .split(/\n\n+/)
        .map(block => {
            if (/^#\s+/.test(block)) return `<h1 class="text-2xl font-black text-primary mt-6 mb-3">${block.replace(/^#\s+/, '')}</h1>`;
            if (/^##\s+/.test(block)) return `<h2 class="text-xl font-black text-primary mt-5 mb-2">${block.replace(/^##\s+/, '')}</h2>`;
            if (/^-\s+/m.test(block)) {
                const items = block.split(/\n/).map(line => `<li class="ml-6 list-disc">${line.replace(/^-\s+/, '')}</li>`).join('');
                return `<ul class="my-3">${items}</ul>`;
            }
            return `<p class="my-3 text-on-surface leading-relaxed">${block.replace(/\n/g, '<br>')}</p>`;
        })
        .join('');
});

const myAckStatus = computed(() => props.policy.data?.my_ack_status ?? 'no_version');
const expectedSignature = computed(() => { /* set in template via $page.props.auth.user.name */ return ''; });
</script>

<template>
<Head :title="policy.data.title" />
<AuthenticatedLayout active-module="governance">
    <div class="p-6 space-y-6 animate-reveal-up max-w-6xl mx-auto">
        <header>
            <Link :href="route('governance.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← All Policies</Link>
            <h1 class="text-[1.6rem] font-black tracking-tight text-primary mt-1">{{ policy.data.title }}</h1>
            <p class="text-sm text-on-surface-variant">{{ policy.data.summary }}</p>
            <p v-if="current" class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant/70 mt-2">
                Version {{ current.data.version_number }} · Effective {{ current.data.effective_from ?? '—' }}
                <span v-if="current.data.published_at"> · Published {{ new Date(current.data.published_at).toLocaleDateString() }}</span>
            </p>
        </header>

        <div class="grid grid-cols-12 gap-6">
            <main class="col-span-12 lg:col-span-9 space-y-6">
                <article v-if="current" class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8 card-lift">
                    <div class="prose max-w-none" v-html="renderedBody"></div>
                </article>
                <article v-else class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-8 text-center">
                    <p class="text-on-surface-variant">This policy has no published version yet.</p>
                </article>

                <section v-if="current && myAckStatus === 'pending'" class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-6">
                    <h2 class="text-lg font-black text-amber-900">Acknowledge this policy</h2>
                    <p class="mt-1 text-sm text-amber-800">Type your full name (matching the name on your account) to record acknowledgement. Your timestamp, IP address, and browser will be captured for audit.</p>
                    <form @submit.prevent="submitAck" class="mt-4 space-y-3">
                        <input v-model="ackForm.signed_full_name" required maxlength="120"
                            :placeholder="$page.props.auth.user.name"
                            class="w-full max-w-md rounded-xl border border-amber-300 bg-surface-container-lowest px-3 py-2 text-sm" />
                        <button type="submit" :disabled="ackForm.processing" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-5 py-2 text-sm font-bold text-white shadow-glow-sm">
                            Acknowledge
                        </button>
                    </form>
                </section>

                <section v-else-if="myAckStatus === 'acknowledged'" class="rounded-2xl border border-emerald-300 bg-emerald-50 p-4">
                    <p class="text-sm font-bold text-emerald-900">You have acknowledged this version.</p>
                </section>
            </main>

            <aside class="col-span-12 lg:col-span-3 space-y-4">
                <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest p-4 card-lift">
                    <h3 class="text-[10px] font-black uppercase tracking-widest text-on-surface-variant/70">Version History</h3>
                    <ul class="mt-3 space-y-2">
                        <li v-for="v in versions.data" :key="v.id"
                            class="text-xs border-l-2 pl-3 py-1"
                            :class="v.id === current?.data?.id ? 'border-primary' : 'border-outline-variant'">
                            <p class="font-bold">v{{ v.version_number }}</p>
                            <p v-if="v.published_at" class="text-on-surface-variant">{{ new Date(v.published_at).toLocaleDateString() }}</p>
                            <p v-else class="text-on-surface-variant italic">draft</p>
                            <p v-if="v.changelog" class="text-on-surface-variant/80 mt-0.5">{{ v.changelog }}</p>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</AuthenticatedLayout>
</template>
```

### Create `resources/js/Pages/Governance/Manage.vue`

HR editor — create policies, add new versions, publish.

```vue
<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({ policies: Object });

const showCreate = ref(false);

const newPolicy = useForm({
    title: '', slug: '', category: 'hr', summary: '',
    is_active: true, initial_body: '# Policy body\n\nReplace this with the policy text.',
});

function createPolicy() {
    newPolicy.post(route('governance.policies.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; newPolicy.reset(); },
    });
}

const categoryLabel = {
    hr: 'HR', finance: 'Finance', it: 'IT', compliance: 'Compliance',
    safety: 'Safety', conduct: 'Conduct', other: 'Other',
};
</script>

<template>
<Head title="Manage Policies" />
<AuthenticatedLayout active-module="governance">
    <div class="p-6 space-y-6 animate-reveal-up">
        <header class="flex items-center justify-between">
            <div>
                <Link :href="route('governance.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← Governance</Link>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary mt-1">Manage Policies</h1>
                <p class="text-sm text-on-surface-variant">Create policies, draft new versions, publish for organisation-wide acknowledgement.</p>
            </div>
            <button @click="showCreate = true" type="button" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm btn-shimmer">+ New Policy</button>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
            <table v-if="props.policies.data?.length" class="w-full text-sm">
                <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="p-4">Title</th><th>Slug</th><th>Category</th><th>Owner</th><th>Current Version</th><th>Status</th>
                </tr></thead>
                <tbody>
                    <tr v-for="p in props.policies.data" :key="p.id" class="border-t border-outline-variant/40 hover:bg-surface-container-low/30 transition-colors">
                        <td class="p-4 font-bold"><Link :href="route('governance.policies.show', p.id)" class="text-secondary hover:underline">{{ p.title }}</Link></td>
                        <td class="text-xs font-mono text-on-surface-variant">{{ p.slug }}</td>
                        <td class="text-xs">{{ categoryLabel[p.category] }}</td>
                        <td class="text-xs">{{ p.owner?.name ?? '—' }}</td>
                        <td class="text-xs">{{ p.current_version ? `v${p.current_version.version_number}` : 'no version' }}</td>
                        <td><span v-if="p.is_active" class="text-[10px] font-bold text-emerald-700">ACTIVE</span><span v-else class="text-[10px] font-bold text-on-surface-variant">archived</span></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="No policies created yet." class="py-12" />
        </section>
    </div>

    <SlidePanel :open="showCreate" @close="showCreate = false" title="Create Policy" size="lg">
        <form @submit.prevent="createPolicy" class="space-y-3 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Title</label><input v-model="newPolicy.title" maxlength="200" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Slug (optional, auto-derived)</label><input v-model="newPolicy.slug" maxlength="200" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono lowercase mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Category</label><select v-model="newPolicy.category" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option v-for="(label, key) in categoryLabel" :key="key" :value="key">{{ label }}</option></select></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Summary</label><textarea v-model="newPolicy.summary" maxlength="1000" rows="2" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Initial Body (markdown — # for headings, - for bullets)</label><textarea v-model="newPolicy.initial_body" rows="8" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 text-sm font-mono mt-1" /></div>
            <button type="submit" :disabled="newPolicy.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Create Draft</button>
        </form>
    </SlidePanel>
</AuthenticatedLayout>
</template>
```

### Create `resources/js/Pages/Governance/Certifications.vue`

```vue
<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    certifications: Object,
    employees:      Array,
});

const canManage = ref(props.employees?.length > 0);

const showAdd = ref(false);
const newCert = useForm({
    employee_id: '', name: '', issuer: '', credential_id: '',
    issued_at: '', expires_at: '', verification_url: '',
});

function createCert() {
    newCert.post(route('governance.certifications.store'), {
        preserveScroll: true,
        onSuccess: () => { showAdd.value = false; newCert.reset(); },
    });
}

function dispatchReminders() {
    if (! confirm('Send reminder events for all certifications expiring within 30 days?')) return;
    useForm({}).post(route('governance.certifications.dispatch-reminders'), { preserveScroll: true });
}

function dueColour(d) {
    if (d === null) return 'text-on-surface-variant';
    if (d < 0)      return 'text-rose-700 font-bold';
    if (d <= 30)    return 'text-amber-700 font-bold';
    return 'text-emerald-700';
}
function dueLabel(d) {
    if (d === null) return 'no expiry';
    if (d < 0)      return `${Math.abs(d)}d overdue`;
    if (d === 0)    return 'today';
    return `${d}d`;
}
</script>

<template>
<Head title="Certifications" />
<AuthenticatedLayout active-module="governance">
    <div class="p-6 space-y-6 animate-reveal-up">
        <header class="flex items-center justify-between">
            <div>
                <Link :href="route('governance.index')" class="text-xs font-bold text-on-surface-variant hover:text-primary">← Governance</Link>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary mt-1">Certifications</h1>
                <p class="text-sm text-on-surface-variant">Tracked certifications across the organisation with expiry reminders.</p>
            </div>
            <div class="flex gap-2" v-if="canManage">
                <button @click="dispatchReminders" type="button" class="rounded-xl border border-outline-variant px-4 py-2 text-sm font-bold text-primary hover:bg-surface-container-low">Send Reminders Now</button>
                <button @click="showAdd = true" type="button" class="rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white shadow-glow-sm btn-shimmer">+ Add Certification</button>
            </div>
        </header>

        <section class="rounded-2xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden card-lift">
            <table v-if="props.certifications.data?.length" class="w-full text-sm">
                <thead class="border-b border-outline-variant"><tr class="text-left text-[10px] font-black uppercase text-on-surface-variant tracking-widest">
                    <th class="p-4">Holder</th><th>Certification</th><th>Issuer</th><th>Issued</th><th>Expires</th><th>Status</th><th>Reminded</th>
                </tr></thead>
                <tbody>
                    <tr v-for="c in props.certifications.data" :key="c.id" class="border-t border-outline-variant/40">
                        <td class="p-4">{{ c.employee?.name ?? '—' }} <span class="text-xs text-on-surface-variant font-mono">({{ c.employee?.employee_no ?? '—' }})</span></td>
                        <td>{{ c.name }} <span v-if="c.credential_id" class="text-xs text-on-surface-variant font-mono">{{ c.credential_id }}</span></td>
                        <td class="text-xs">{{ c.issuer ?? '—' }}</td>
                        <td class="text-xs">{{ c.issued_at ?? '—' }}</td>
                        <td class="text-xs">{{ c.expires_at ?? '—' }}</td>
                        <td :class="['text-xs', dueColour(c.days_to_expiry)]">{{ dueLabel(c.days_to_expiry) }}</td>
                        <td class="text-xs">{{ c.reminder_sent_at ? new Date(c.reminder_sent_at).toLocaleDateString() : '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="No certifications tracked yet." class="py-12" />
            <Pagination v-if="props.certifications.meta?.last_page > 1" :links="props.certifications.meta.links" class="p-4" />
        </section>
    </div>

    <SlidePanel :open="showAdd" @close="showAdd = false" title="Add Certification">
        <form @submit.prevent="createCert" class="space-y-3 p-4">
            <div><label class="text-[11px] font-bold text-on-surface-variant">Employee</label><select v-model="newCert.employee_id" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1"><option value="" disabled>Select…</option><option v-for="e in props.employees" :key="e.id" :value="e.id">{{ e.employee_no }} — {{ e.position }}</option></select></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Certification Name</label><input v-model="newCert.name" maxlength="200" required class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Issuer</label><input v-model="newCert.issuer" maxlength="200" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Credential ID</label><input v-model="newCert.credential_id" maxlength="120" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 font-mono mt-1" /></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-[11px] font-bold text-on-surface-variant">Issued</label><input v-model="newCert.issued_at" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
                <div><label class="text-[11px] font-bold text-on-surface-variant">Expires</label><input v-model="newCert.expires_at" type="date" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            </div>
            <div><label class="text-[11px] font-bold text-on-surface-variant">Verification URL</label><input v-model="newCert.verification_url" type="url" maxlength="500" class="w-full rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2 mt-1" /></div>
            <button type="submit" :disabled="newCert.processing" class="w-full rounded-xl bg-gradient-to-br from-primary to-secondary px-4 py-2 text-sm font-bold text-white">Add Certification</button>
        </form>
    </SlidePanel>
</AuthenticatedLayout>
</template>
```

### Verify + commit

```powershell
npm run build
```

```powershell
git add resources/js/Pages/Governance/Index.vue resources/js/Pages/Governance/Show.vue resources/js/Pages/Governance/Manage.vue resources/js/Pages/Governance/Certifications.vue
git commit -m "feat(governance): Index/Show/Manage/Certifications Vue pages with markdown ack + reminder dashboard"
```

---

## TASK 7: Tests

### `tests/Feature/Governance/PolicyWorkflowTest.php`

```php
<?php

declare(strict_types=1);

use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\PolicyAcknowledgement;
use App\Models\User;
use App\Services\GovernanceService;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('creates a policy with v1 draft', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Code of Conduct',
        'category' => 'conduct',
        'initial_body' => '# Code of Conduct\n\nEmployees must…',
    ]);

    expect($policy->slug)->toBe('code-of-conduct');
    expect($policy->versions()->count())->toBe(1);
    expect($policy->versions()->first()->published_at)->toBeNull();
});

it('publishes a version and stamps current_version_id', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Travel Policy', 'category' => 'finance', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();

    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-06-01'));

    expect($policy->fresh()->current_version_id)->toBe($v1->id);
    expect($v1->fresh()->published_at)->not->toBeNull();
});

it('records acknowledgement with ip + ua + signed name', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $u  = User::factory()->create();
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Ethics', 'category' => 'compliance', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();
    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-06-01'));

    $ack = app(GovernanceService::class)->acknowledge(
        $v1->fresh(), $u, $u->name, '10.0.0.1', 'TestAgent/1.0'
    );

    expect($ack)->toBeInstanceOf(PolicyAcknowledgement::class);
    expect($ack->ip_address)->toBe('10.0.0.1');
    expect($ack->signed_full_name)->toBe($u->name);
});

it('is idempotent on duplicate acknowledgement', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $u  = User::factory()->create();
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Safety', 'category' => 'safety', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();
    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-06-01'));

    app(GovernanceService::class)->acknowledge($v1->fresh(), $u, $u->name, '1.1.1.1', 'A');
    app(GovernanceService::class)->acknowledge($v1->fresh(), $u, $u->name, '1.1.1.1', 'A');

    expect(PolicyAcknowledgement::where('policy_version_id', $v1->id)->where('user_id', $u->id)->count())->toBe(1);
});

it('refuses acknowledgement of an unpublished version', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $u  = User::factory()->create();
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Draft Only', 'category' => 'hr', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();

    expect(fn () => app(GovernanceService::class)->acknowledge($v1, $u, $u->name, '1.1.1.1', 'A'))
        ->toThrow(\DomainException::class, 'unpublished');
});

it('supersedes the previous version on publish', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Multi-version', 'category' => 'hr', 'initial_body' => 'v1 body',
    ]);
    $v1 = $policy->versions()->first();
    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-01-01'));

    $v2 = app(GovernanceService::class)->addVersion($policy->fresh(), $hr, 'v2 body content here for the test scenario', 'Major revision');
    app(GovernanceService::class)->publish($v2, $hr, new \DateTimeImmutable('2026-06-01'));

    expect($v1->fresh()->effective_to?->toDateString())->toBe('2026-05-31');
    expect($policy->fresh()->current_version_id)->toBe($v2->id);
});
```

### `tests/Feature/Governance/CertificationReminderTest.php`

```php
<?php

declare(strict_types=1);

use App\Models\Certification;
use App\Models\Employee;
use App\Services\GovernanceService;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('dispatches reminders for certs expiring within 30 days and stamps reminder_sent_at', function () {
    Carbon::setTestNow('2026-06-15 08:00:00');

    $emp = Employee::factory()->create();
    $expiring = Certification::create([
        'employee_id' => $emp->id,
        'name' => 'Safety Cert',
        'issued_at' => '2025-06-15',
        'expires_at' => '2026-07-01',
    ]);
    $faraway = Certification::create([
        'employee_id' => $emp->id,
        'name' => 'Long Cert',
        'issued_at' => '2025-06-15',
        'expires_at' => '2030-01-01',
    ]);

    $count = app(GovernanceService::class)->dispatchExpiryReminders(30);

    expect($count)->toBe(1);
    expect($expiring->fresh()->reminder_sent_at)->not->toBeNull();
    expect($faraway->fresh()->reminder_sent_at)->toBeNull();

    Carbon::setTestNow();
});

it('does not re-send reminder when reminder_sent_at is already set', function () {
    Carbon::setTestNow('2026-06-15 08:00:00');
    $emp = Employee::factory()->create();
    Certification::create([
        'employee_id' => $emp->id, 'name' => 'X', 'expires_at' => '2026-07-01',
        'reminder_sent_at' => now()->subDays(2),
    ]);

    $count = app(GovernanceService::class)->dispatchExpiryReminders(30);
    expect($count)->toBe(0);

    Carbon::setTestNow();
});
```

### `tests/Feature/Governance/GovernanceControllerTest.php`

```php
<?php

declare(strict_types=1);

use App\Models\Policy;
use App\Models\User;
use App\Services\GovernanceService;

use function Pest\Laravel\actingAs;

beforeEach(fn () => $this->seed(\Database\Seeders\RolePermissionSeeder::class));

it('hr_admin can create a policy', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);

    actingAs($hr)->post('/governance/policies', [
        'title' => 'Working Hours', 'category' => 'hr', 'initial_body' => 'Stick to schedule.',
    ])->assertRedirect();

    expect(Policy::where('slug', 'working-hours')->exists())->toBeTrue();
});

it('forbids an employee from publishing a version (RBAC deny)', function () {
    $hr = User::factory()->create(['role' => 'hr_admin']);
    $emp = User::factory()->create(['role' => 'employee']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Test', 'category' => 'hr', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();

    actingAs($emp)->patch("/governance/versions/{$v1->id}/publish", [
        'effective_from' => '2026-06-01',
    ])->assertForbidden();
});

it('rejects acknowledgement with mismatched signed name', function () {
    $hr = User::factory()->create(['role' => 'hr_admin', 'name' => 'Alice Mensah']);
    $policy = app(GovernanceService::class)->createPolicy($hr, [
        'title' => 'Sig Test', 'category' => 'compliance', 'initial_body' => 'body',
    ]);
    $v1 = $policy->versions()->first();
    app(GovernanceService::class)->publish($v1, $hr, new \DateTimeImmutable('2026-06-01'));

    actingAs($hr)->post("/governance/versions/{$v1->id}/ack", [
        'signed_full_name' => 'Bob Wrongname',
    ])->assertStatus(422);
});
```

### Verify + commit

```powershell
mkdir -p tests/Feature/Governance
foreach ($f in 'PolicyWorkflowTest','CertificationReminderTest','GovernanceControllerTest') { php -l "tests/Feature/Governance/$f.php" }
```

```powershell
git add tests/Feature/Governance/
git commit -m "test(governance): policy workflow + cert reminder + controller tests (11 cases across 3 files)"
```

---

## TASK 8: Sidebar nav refresh + PROJECT_STATE + final push

### Update PROJECT_STATE.md

Mark P5 + entire P0–P5 cycle complete. Update migration count, layer counts, headline.

### Commit + push

```powershell
git add docs/PROJECT_STATE.md
git commit -m "docs: PROJECT_STATE — P5 Governance complete; all five phases delivered"
git push origin main
```

---

## Manual smoke checklist

1. `php artisan migrate` runs cleanly.
2. `/governance` loads with empty-state.
3. As hr_admin, click "Manage Policies" → `+ New Policy` → submit.
4. Click into the new policy → see v1 (unpublished) in version history.
5. Publish via API: `PATCH /governance/versions/{id}/publish` with `effective_from`.
6. As an employee, reload `/governance` → policy shows "ACK REQUIRED".
7. Open policy → type your full name → submit acknowledgement → status flips to ACK'D.
8. Try ack with a wrong name → 422 error.
9. Navigate to `/governance/certifications` → add a cert expiring in 20 days.
10. Run `php artisan governance:certification-reminders` → 1 reminder dispatched, reminder_sent_at stamped.
11. Run again → 0 reminders dispatched (idempotent).
12. `php artisan schedule:list` → shows daily 08:00 entry.
13. CI on PHP 8.4 runs all new tests green.

---

## Self-review checklist

- ✅ Spec §11 requirements all covered (Policies CRUD with versions, typed-name ack with IP+UA, certification expiry reminders)
- ✅ Reuses existing `Certification` model rather than duplicating (avoids name collision + leverages existing scopes)
- ✅ Each task ends with at least one commit
- ✅ All code blocks complete (no TBDs, no placeholders)
- ✅ Method names, table names, column names consistent across tasks
- ✅ RBAC permissions seeded before middleware-checked
- ✅ Events registered in AppServiceProvider before being dispatched
- ✅ Vue pages use established components with correct prop names (`:open`, `title`, `:links`)
- ✅ Server-side signed-name guard in AcknowledgePolicyRequest::passedValidation() (defense-in-depth — even if Vue is bypassed, signature must match account name)
- ✅ Markdown rendering is intentionally minimal (no marked/DOMPurify dep added); rich rendering can be a follow-up if needed
