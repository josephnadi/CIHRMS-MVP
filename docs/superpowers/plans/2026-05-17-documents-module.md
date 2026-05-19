# Documents Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a CIHRMS Documents module that lets users upload documents, drop signatures/stamps on them, route them through an ordered list of recipients across portals, and download burned-in PDFs — replacing the physical "memo + stamp + walk it to the next desk" flow.

**Architecture:** Backend is the standard CIHRMS Enum→FormRequest→Service→Event→Resource pattern (5 models, 4 services). Routing slip is sequential (one hop active at a time). Annotations stored as JSON overlay records, optionally burned into a PDF server-side at download time via FPDI+TCPDF. Frontend is Inertia v2 + Vue 3 with pdf.js viewer, signature_pad canvas, and a right-rail routing slip + timeline.

**Tech Stack:** Laravel 13.7, Vue 3, Inertia.js v2, Tailwind v3. New deps: `setasign/fpdi`, `tecnickcom/tcpdf` (composer); `pdfjs-dist`, `signature_pad` (npm).

**Spec reference:** `docs/superpowers/specs/2026-05-17-documents-module-design.md`

**Note on git:** The CIHRMS workspace is **not a git repo**. Commit steps below are written for the standard workflow; if running here, replace `git commit` with a logical checkpoint (mark the task complete and run the build to verify state). The plan keeps commit steps so it remains executable if a repo is initialised later.

---

## File map (what gets created or modified)

### New backend files

```
app/Enums/
├── DocumentStatus.php
├── DocumentConfidentiality.php
├── DocumentRouteAction.php
├── DocumentRouteStatus.php
├── DocumentAnnotationType.php
└── DocumentEventType.php

app/Models/
├── Document.php
├── DocumentVersion.php
├── DocumentRoute.php
├── DocumentAnnotation.php
└── DocumentEvent.php

app/Http/Controllers/DocumentController.php
app/Http/Requests/Documents/{Store,AddVersion,Route,Annotate,ActOnRoute,Withdraw}DocumentRequest.php
app/Http/Resources/{Document,DocumentRoute,DocumentAnnotation,DocumentEvent}Resource.php

app/Services/
├── DocumentService.php
├── DocumentRoutingService.php
├── DocumentRenderService.php
└── DocumentConversionService.php

app/Events/
├── DocumentRouted.php
├── DocumentSigned.php
├── DocumentCompleted.php
└── DocumentRejected.php

app/Notifications/
├── DocumentAwaitingAction.php
└── DocumentCompletedNotice.php

app/Policies/DocumentPolicy.php
app/Exceptions/ConversionNotSupportedException.php

database/migrations/
├── 2026_05_17_000001_create_documents_table.php
├── 2026_05_17_000002_create_document_versions_table.php
├── 2026_05_17_000003_create_document_routes_table.php
├── 2026_05_17_000004_create_document_annotations_table.php
└── 2026_05_17_000005_create_document_events_table.php

database/seeders/DocumentPermissionsSeeder.php
```

### Modified backend files
- `routes/web.php` — append document route group
- `composer.json` — add `setasign/fpdi`, `tecnickcom/tcpdf`
- `app/Providers/AuthServiceProvider.php` — register policy
- `database/seeders/DatabaseSeeder.php` — call `DocumentPermissionsSeeder`

### New frontend files
```
resources/js/Pages/Documents/
├── Index.vue
└── Show.vue

resources/js/Components/Documents/
├── Viewer.vue
├── SignaturePad.vue
├── StampPicker.vue
├── AnnotationLayer.vue
├── RoutingSlipPanel.vue
└── TimelineRail.vue
```

### Modified frontend files
- `resources/js/Layouts/AuthenticatedLayout.vue` — add Documents nav entry
- `package.json` — add `pdfjs-dist`, `signature_pad`

### Tests
```
tests/Feature/Documents/
├── UploadDocumentTest.php
├── RouteDocumentTest.php
├── ActOnRouteTest.php
├── AnnotateDocumentTest.php
├── DownloadDocumentTest.php
└── WithdrawDocumentTest.php

tests/Unit/Services/
└── DocumentRoutingServiceTest.php
```

---

## Task 1: Install dependencies

**Files:**
- Modify: `composer.json`
- Modify: `package.json`

- [ ] **Step 1: Install composer packages**

Run from `d:\CIHRMS\cihrms-mvp`:
```bash
composer require setasign/fpdi:^2.6 tecnickcom/tcpdf:^6.7
```
Expected: both packages installed; `composer.lock` updated.

- [ ] **Step 2: Install npm packages**

Run from `d:\CIHRMS\cihrms-mvp`:
```bash
npm install pdfjs-dist@^4.0.0 signature_pad@^4.2.0
```
Expected: both packages added to `package.json` under `dependencies`.

- [ ] **Step 3: Verify build still passes**

Run:
```bash
npm run build
```
Expected: `✓ built in <time>` with no errors.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json
git commit -m "feat(documents): install fpdi, tcpdf, pdfjs-dist, signature_pad"
```

---

## Task 2: Create enums

**Files:**
- Create: `app/Enums/DocumentStatus.php`
- Create: `app/Enums/DocumentConfidentiality.php`
- Create: `app/Enums/DocumentRouteAction.php`
- Create: `app/Enums/DocumentRouteStatus.php`
- Create: `app/Enums/DocumentAnnotationType.php`
- Create: `app/Enums/DocumentEventType.php`

- [ ] **Step 1: Create `DocumentStatus`**

```php
<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft     = 'draft';
    case InReview  = 'in_review';
    case Completed = 'completed';
    case Rejected  = 'rejected';
    case Withdrawn = 'withdrawn';
    case Archived  = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::InReview  => 'In Review',
            self::Completed => 'Completed',
            self::Rejected  => 'Rejected',
            self::Withdrawn => 'Withdrawn',
            self::Archived  => 'Archived',
        };
    }
}
```

- [ ] **Step 2: Create `DocumentConfidentiality`**

```php
<?php

namespace App\Enums;

enum DocumentConfidentiality: string
{
    case Internal     = 'internal';
    case Confidential = 'confidential';
    case Restricted   = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Internal     => 'Internal',
            self::Confidential => 'Confidential',
            self::Restricted   => 'Restricted',
        };
    }
}
```

- [ ] **Step 3: Create `DocumentRouteAction`**

```php
<?php

namespace App\Enums;

enum DocumentRouteAction: string
{
    case Sign        = 'sign';
    case Review      = 'review';
    case Approve     = 'approve';
    case Acknowledge = 'acknowledge';

    public function label(): string
    {
        return match ($this) {
            self::Sign        => 'Sign',
            self::Review      => 'Review',
            self::Approve     => 'Approve',
            self::Acknowledge => 'Acknowledge',
        };
    }
}
```

- [ ] **Step 4: Create `DocumentRouteStatus`**

```php
<?php

namespace App\Enums;

enum DocumentRouteStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Rejected   = 'rejected';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Rejected   => 'Rejected',
            self::Cancelled  => 'Cancelled',
        };
    }
}
```

- [ ] **Step 5: Create `DocumentAnnotationType`**

```php
<?php

namespace App\Enums;

enum DocumentAnnotationType: string
{
    case Signature = 'signature';
    case Stamp     = 'stamp';
    case Text      = 'text';
    case Initial   = 'initial';
    case Highlight = 'highlight';
}
```

- [ ] **Step 6: Create `DocumentEventType`**

```php
<?php

namespace App\Enums;

enum DocumentEventType: string
{
    case Uploaded     = 'uploaded';
    case VersionAdded = 'version_added';
    case Routed       = 'routed';
    case Annotated    = 'annotated';
    case Signed       = 'signed';
    case Stamped      = 'stamped';
    case Forwarded    = 'forwarded';
    case Rejected     = 'rejected';
    case Completed    = 'completed';
    case Withdrawn    = 'withdrawn';
    case Downloaded   = 'downloaded';
    case Archived     = 'archived';
}
```

- [ ] **Step 7: Commit**

```bash
git add app/Enums/Document*.php
git commit -m "feat(documents): add document enums (status, confidentiality, route, annotation, event)"
```

---

## Task 3: Create migrations

**Files:**
- Create: `database/migrations/2026_05_17_000001_create_documents_table.php`
- Create: `database/migrations/2026_05_17_000002_create_document_versions_table.php`
- Create: `database/migrations/2026_05_17_000003_create_document_routes_table.php`
- Create: `database/migrations/2026_05_17_000004_create_document_annotations_table.php`
- Create: `database/migrations/2026_05_17_000005_create_document_events_table.php`

- [ ] **Step 1: Create `documents` migration**

`database/migrations/2026_05_17_000001_create_documents_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('ref_no')->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->string('status')->default('draft')->index();
            $t->string('confidentiality')->default('internal');
            $t->boolean('parallel_routing')->default(false);
            $t->json('tags')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

- [ ] **Step 2: Create `document_versions` migration**

`database/migrations/2026_05_17_000002_create_document_versions_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->unsignedSmallInteger('version_no');
            $t->string('original_name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('storage_path');
            $t->string('sha256', 64)->index();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamp('uploaded_at');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'version_no']);
        });

        Schema::table('documents', function (Blueprint $t) {
            $t->foreign('current_version_id')
              ->references('id')->on('document_versions')
              ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('document_versions');
    }
};
```

- [ ] **Step 3: Create `document_routes` migration**

`database/migrations/2026_05_17_000003_create_document_routes_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignId('version_id')->constrained('document_versions')->cascadeOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->foreignId('from_user_id')->constrained('users');
            $t->foreignId('to_user_id')->constrained('users');
            $t->string('action_required')->default('sign');
            $t->string('status')->default('pending');
            $t->timestamp('due_at')->nullable();
            $t->timestamp('acted_at')->nullable();
            $t->text('comment')->nullable();
            $t->timestamps();
            $t->index(['to_user_id', 'status']);
            $t->index(['document_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_routes');
    }
};
```

- [ ] **Step 4: Create `document_annotations` migration**

`database/migrations/2026_05_17_000004_create_document_annotations_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_annotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignId('version_id')->constrained('document_versions')->cascadeOnDelete();
            $t->foreignId('route_id')->nullable()->constrained('document_routes')->nullOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('type');
            $t->unsignedSmallInteger('page')->default(1);
            $t->decimal('x_pct', 7, 4);
            $t->decimal('y_pct', 7, 4);
            $t->decimal('w_pct', 7, 4)->default(10);
            $t->decimal('h_pct', 7, 4)->default(5);
            $t->smallInteger('rotation')->default(0);
            $t->json('data');
            $t->timestamps();
            $t->index(['document_id', 'version_id', 'page']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_annotations');
    }
};
```

- [ ] **Step 5: Create `document_events` migration**

`database/migrations/2026_05_17_000005_create_document_events_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('type');
            $t->json('payload')->nullable();
            $t->timestamp('occurred_at')->index();
            $t->timestamps();
            $t->index(['document_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
    }
};
```

- [ ] **Step 6: Run migrations**

```bash
php artisan migrate
```
Expected: all 5 migrations ran successfully.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_05_17_*
git commit -m "feat(documents): add documents, versions, routes, annotations, events tables"
```

---

## Task 4: Create models

**Files:**
- Create: `app/Models/Document.php`
- Create: `app/Models/DocumentVersion.php`
- Create: `app/Models/DocumentRoute.php`
- Create: `app/Models/DocumentAnnotation.php`
- Create: `app/Models/DocumentEvent.php`

- [ ] **Step 1: Create `Document` model**

```php
<?php

namespace App\Models;

use App\Enums\DocumentConfidentiality;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'ref_no', 'title', 'description', 'owner_id',
        'current_version_id', 'status', 'confidentiality',
        'parallel_routing', 'tags',
    ];

    protected $casts = [
        'status'           => DocumentStatus::class,
        'confidentiality'  => DocumentConfidentiality::class,
        'parallel_routing' => 'boolean',
        'tags'             => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Document $doc) {
            $doc->uuid ??= (string) Str::uuid();
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_no');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class)->orderBy('sequence');
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(DocumentAnnotation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('occurred_at');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
```

- [ ] **Step 2: Create `DocumentVersion` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    protected $fillable = [
        'document_id', 'version_no', 'original_name', 'mime', 'size',
        'storage_path', 'sha256', 'uploaded_by', 'uploaded_at', 'notes',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'size'        => 'integer',
        'version_no'  => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```

- [ ] **Step 3: Create `DocumentRoute` model**

```php
<?php

namespace App\Models;

use App\Enums\DocumentRouteAction;
use App\Enums\DocumentRouteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRoute extends Model
{
    protected $fillable = [
        'document_id', 'version_id', 'sequence',
        'from_user_id', 'to_user_id',
        'action_required', 'status',
        'due_at', 'acted_at', 'comment',
    ];

    protected $casts = [
        'action_required' => DocumentRouteAction::class,
        'status'          => DocumentRouteStatus::class,
        'due_at'          => 'datetime',
        'acted_at'        => 'datetime',
        'sequence'        => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
```

- [ ] **Step 4: Create `DocumentAnnotation` model**

```php
<?php

namespace App\Models;

use App\Enums\DocumentAnnotationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAnnotation extends Model
{
    protected $fillable = [
        'document_id', 'version_id', 'route_id', 'user_id',
        'type', 'page', 'x_pct', 'y_pct', 'w_pct', 'h_pct',
        'rotation', 'data',
    ];

    protected $casts = [
        'type'     => DocumentAnnotationType::class,
        'data'     => 'array',
        'page'     => 'integer',
        'rotation' => 'integer',
        'x_pct'    => 'float',
        'y_pct'    => 'float',
        'w_pct'    => 'float',
        'h_pct'    => 'float',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Create `DocumentEvent` model**

```php
<?php

namespace App\Models;

use App\Enums\DocumentEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEvent extends Model
{
    protected $fillable = ['document_id', 'actor_id', 'type', 'payload', 'occurred_at'];

    protected $casts = [
        'type'        => DocumentEventType::class,
        'payload'     => 'array',
        'occurred_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Models/Document*.php
git commit -m "feat(documents): add Document, Version, Route, Annotation, Event models"
```

---

## Task 5: Policy + permissions seeder

**Files:**
- Create: `app/Policies/DocumentPolicy.php`
- Create: `app/Exceptions/ConversionNotSupportedException.php`
- Create: `database/seeders/DocumentPermissionsSeeder.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Create `ConversionNotSupportedException`**

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class ConversionNotSupportedException extends RuntimeException
{
    public static function forFormat(string $from, string $to): self
    {
        return new self("Conversion from {$from} to {$to} is not supported on this server.");
    }
}
```

- [ ] **Step 2: Create `DocumentPolicy`**

```php
<?php

namespace App\Policies;

use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('documents.view');
    }

    public function view(User $user, Document $doc): bool
    {
        if ($user->hasPermissionTo('documents.manage')) return true;
        if ($doc->owner_id === $user->id) return true;
        return $doc->routes()->where('to_user_id', $user->id)->exists()
            || $doc->routes()->where('from_user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('documents.create');
    }

    public function update(User $user, Document $doc): bool
    {
        return $doc->owner_id === $user->id && $doc->status === DocumentStatus::Draft;
    }

    public function delete(User $user, Document $doc): bool
    {
        if ($user->hasPermissionTo('documents.manage')) return true;
        return $doc->owner_id === $user->id && $doc->status === DocumentStatus::Draft;
    }

    public function route(User $user, Document $doc): bool
    {
        return $doc->owner_id === $user->id && $doc->status === DocumentStatus::Draft;
    }

    public function withdraw(User $user, Document $doc): bool
    {
        if ($user->hasPermissionTo('documents.manage')) return true;
        return $doc->owner_id === $user->id && $doc->status === DocumentStatus::InReview;
    }

    public function annotate(User $user, Document $doc): bool
    {
        if ($doc->owner_id === $user->id && $doc->status === DocumentStatus::Draft) {
            return true;
        }
        return $doc->routes()
            ->where('to_user_id', $user->id)
            ->where('status', DocumentRouteStatus::InProgress->value)
            ->exists();
    }

    public function act(User $user, Document $doc, DocumentRoute $route): bool
    {
        return $route->to_user_id === $user->id
            && $route->status === DocumentRouteStatus::InProgress
            && $route->document_id === $doc->id;
    }

    public function manage(User $user): bool
    {
        return $user->hasPermissionTo('documents.manage');
    }
}
```

- [ ] **Step 3: Register policy in `AuthServiceProvider`**

Open `app/Providers/AuthServiceProvider.php`. In the `$policies` array (or `boot()` if using gates), add:

```php
use App\Models\Document;
use App\Policies\DocumentPolicy;
// ...
protected $policies = [
    // ... existing entries
    Document::class => DocumentPolicy::class,
];
```

- [ ] **Step 4: Create `DocumentPermissionsSeeder`**

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DocumentPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $perms = ['documents.view', 'documents.create', 'documents.manage'];
        foreach ($perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Role::all() as $role) {
            if (in_array($role->name, ['admin', 'super-admin'], true)) {
                $role->givePermissionTo($perms);
            } else {
                $role->givePermissionTo(['documents.view', 'documents.create']);
            }
        }

        $registrar = Role::where('name', 'registrar')->first();
        if ($registrar) {
            $registrar->givePermissionTo('documents.manage');
        }
    }
}
```

- [ ] **Step 5: Wire seeder into `DatabaseSeeder`**

Open `database/seeders/DatabaseSeeder.php` and in the `run()` method, add the new seeder call after existing permission seeders:

```php
$this->call([
    // ... existing seeders
    DocumentPermissionsSeeder::class,
]);
```

- [ ] **Step 6: Run seeder**

```bash
php artisan db:seed --class=DocumentPermissionsSeeder
```
Expected: no errors; permissions created in `permissions` table.

- [ ] **Step 7: Commit**

```bash
git add app/Policies/DocumentPolicy.php app/Exceptions/ConversionNotSupportedException.php database/seeders/DocumentPermissionsSeeder.php app/Providers/AuthServiceProvider.php database/seeders/DatabaseSeeder.php
git commit -m "feat(documents): add policy, permissions seeder, conversion exception"
```

---

## Task 6: DocumentService

**Files:**
- Create: `app/Services/DocumentService.php`

- [ ] **Step 1: Create `DocumentService`**

```php
<?php

namespace App\Services;

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentEvent;
use App\Models\DocumentRoute;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    private const DISK = 'local';

    public function upload(UploadedFile $file, array $attrs, User $owner): Document
    {
        return DB::transaction(function () use ($file, $attrs, $owner) {
            $doc = Document::create([
                'ref_no'          => $this->nextRefNo(),
                'title'           => $attrs['title'],
                'description'    => $attrs['description'] ?? null,
                'owner_id'        => $owner->id,
                'status'          => DocumentStatus::Draft,
                'confidentiality' => $attrs['confidentiality'] ?? 'internal',
                'tags'            => $attrs['tags'] ?? null,
            ]);

            $version = $this->storeVersion($doc, $file, $owner, versionNo: 1);
            $doc->update(['current_version_id' => $version->id]);

            $this->logEvent($doc, $owner, DocumentEventType::Uploaded, [
                'version_id'    => $version->id,
                'original_name' => $version->original_name,
            ]);

            return $doc->fresh(['currentVersion', 'owner']);
        });
    }

    public function addVersion(Document $doc, UploadedFile $file, User $by, ?string $notes = null): DocumentVersion
    {
        return DB::transaction(function () use ($doc, $file, $by, $notes) {
            $next = ($doc->versions()->max('version_no') ?? 0) + 1;
            $version = $this->storeVersion($doc, $file, $by, $next, $notes);

            $doc->update(['current_version_id' => $version->id]);

            $this->logEvent($doc, $by, DocumentEventType::VersionAdded, [
                'version_id' => $version->id,
                'version_no' => $next,
            ]);

            return $version;
        });
    }

    public function saveAnnotation(Document $doc, ?DocumentRoute $route, User $by, array $attrs): DocumentAnnotation
    {
        return DB::transaction(function () use ($doc, $route, $by, $attrs) {
            $annotation = $doc->annotations()->create([
                'version_id' => $doc->current_version_id,
                'route_id'   => $route?->id,
                'user_id'    => $by->id,
                'type'       => $attrs['type'],
                'page'       => $attrs['page'] ?? 1,
                'x_pct'      => $attrs['x_pct'],
                'y_pct'      => $attrs['y_pct'],
                'w_pct'      => $attrs['w_pct'] ?? 10,
                'h_pct'      => $attrs['h_pct'] ?? 5,
                'rotation'   => $attrs['rotation'] ?? 0,
                'data'       => $attrs['data'],
            ]);

            $eventType = match ($attrs['type']) {
                'signature', 'initial' => DocumentEventType::Signed,
                'stamp'                => DocumentEventType::Stamped,
                default                => DocumentEventType::Annotated,
            };

            $this->logEvent($doc, $by, $eventType, [
                'annotation_id' => $annotation->id,
                'page'          => $annotation->page,
                'type'          => $annotation->type->value,
            ]);

            return $annotation;
        });
    }

    public function removeAnnotation(DocumentAnnotation $annotation, User $by): void
    {
        // Only author can delete, and only if document is in draft or the user's route is still in_progress.
        $annotation->delete();
    }

    public function archive(Document $doc, User $by): Document
    {
        $doc->update(['status' => DocumentStatus::Archived]);
        $this->logEvent($doc, $by, DocumentEventType::Archived);
        return $doc;
    }

    public function logEvent(Document $doc, User $actor, DocumentEventType $type, array $payload = []): DocumentEvent
    {
        return DocumentEvent::create([
            'document_id' => $doc->id,
            'actor_id'    => $actor->id,
            'type'        => $type,
            'payload'     => $payload,
            'occurred_at' => now(),
        ]);
    }

    private function storeVersion(Document $doc, UploadedFile $file, User $by, int $versionNo, ?string $notes = null): DocumentVersion
    {
        $path = sprintf('documents/%s/v%d/%s', $doc->uuid, $versionNo, $file->getClientOriginalName());
        Storage::disk(self::DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        return DocumentVersion::create([
            'document_id'   => $doc->id,
            'version_no'    => $versionNo,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'storage_path'  => $path,
            'sha256'        => hash_file('sha256', $file->getRealPath()),
            'uploaded_by'   => $by->id,
            'uploaded_at'   => now(),
            'notes'         => $notes,
        ]);
    }

    private function nextRefNo(): string
    {
        $year = now()->year;
        $count = Document::whereYear('created_at', $year)->count() + 1;
        return sprintf('CIHRMS/DOC/%d/%04d', $year, $count);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/DocumentService.php
git commit -m "feat(documents): add DocumentService (upload, version, annotate, archive)"
```

---

## Task 7: DocumentRoutingService (TDD)

**Files:**
- Create: `tests/Unit/Services/DocumentRoutingServiceTest.php`
- Create: `app/Services/DocumentRoutingService.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/Services/DocumentRoutingServiceTest.php`:
```php
<?php

use App\Enums\DocumentRouteAction;
use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentRoutingService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(DocumentRoutingService::class);
    $this->owner = User::factory()->create();
    $this->alice = User::factory()->create();
    $this->bob   = User::factory()->create();

    $this->doc = Document::factory()->for($this->owner, 'owner')->create([
        'status' => DocumentStatus::Draft,
    ]);
    $this->version = DocumentVersion::factory()->for($this->doc)->create();
    $this->doc->update(['current_version_id' => $this->version->id]);
});

it('creates ordered routes and marks first in_progress', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
        ['user_id' => $this->bob->id,   'action_required' => DocumentRouteAction::Approve],
    ]);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::InReview);

    $routes = $this->doc->routes()->orderBy('sequence')->get();
    expect($routes)->toHaveCount(2);
    expect($routes[0]->to_user_id)->toBe($this->alice->id);
    expect($routes[0]->status)->toBe(DocumentRouteStatus::InProgress);
    expect($routes[1]->status)->toBe(DocumentRouteStatus::Pending);
});

it('advances to next hop on complete action', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
        ['user_id' => $this->bob->id,   'action_required' => DocumentRouteAction::Approve],
    ]);
    $route1 = $this->doc->routes()->orderBy('sequence')->first();

    $this->service->act($route1, 'complete', null, $this->alice);

    $routes = $this->doc->routes()->orderBy('sequence')->get();
    expect($routes[0]->status)->toBe(DocumentRouteStatus::Completed);
    expect($routes[1]->status)->toBe(DocumentRouteStatus::InProgress);
    expect($this->doc->fresh()->status)->toBe(DocumentStatus::InReview);
});

it('completes the document when last hop is acted', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
    ]);
    $route = $this->doc->routes()->first();

    $this->service->act($route, 'complete', null, $this->alice);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Completed);
    expect($route->fresh()->status)->toBe(DocumentRouteStatus::Completed);
});

it('rejects the document and marks subsequent routes cancelled', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
        ['user_id' => $this->bob->id,   'action_required' => DocumentRouteAction::Approve],
    ]);
    $route1 = $this->doc->routes()->orderBy('sequence')->first();

    $this->service->act($route1, 'reject', 'Wrong document', $this->alice);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Rejected);
    $routes = $this->doc->routes()->orderBy('sequence')->get();
    expect($routes[0]->status)->toBe(DocumentRouteStatus::Rejected);
    expect($routes[1]->status)->toBe(DocumentRouteStatus::Cancelled);
});

it('withdraws an in-review document and cancels in-progress route', function () {
    $this->service->route($this->doc, [
        ['user_id' => $this->alice->id, 'action_required' => DocumentRouteAction::Sign],
    ]);

    $this->service->withdraw($this->doc, $this->owner);

    expect($this->doc->fresh()->status)->toBe(DocumentStatus::Withdrawn);
    expect($this->doc->routes()->first()->fresh()->status)->toBe(DocumentRouteStatus::Cancelled);
});
```

You will also need factories — create `database/factories/DocumentFactory.php` and `DocumentVersionFactory.php`:

`database/factories/DocumentFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\DocumentConfidentiality;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;
        return [
            'uuid'            => (string) \Illuminate\Support\Str::uuid(),
            'ref_no'          => sprintf('CIHRMS/DOC/%d/%04d', now()->year, $seq),
            'title'           => fake()->sentence(4),
            'description'     => fake()->sentence(),
            'owner_id'        => User::factory(),
            'status'          => DocumentStatus::Draft,
            'confidentiality' => DocumentConfidentiality::Internal,
        ];
    }
}
```

`database/factories/DocumentVersionFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    public function definition(): array
    {
        return [
            'document_id'   => Document::factory(),
            'version_no'    => 1,
            'original_name' => 'sample.pdf',
            'mime'          => 'application/pdf',
            'size'          => 1024,
            'storage_path'  => 'documents/dummy/v1/sample.pdf',
            'sha256'        => str_repeat('a', 64),
            'uploaded_by'   => User::factory(),
            'uploaded_at'   => now(),
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=DocumentRoutingServiceTest
```
Expected: FAIL with "Class App\Services\DocumentRoutingService not found".

- [ ] **Step 3: Create `DocumentRoutingService`**

```php
<?php

namespace App\Services;

use App\Enums\DocumentEventType;
use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Events\DocumentCompleted;
use App\Events\DocumentRejected;
use App\Events\DocumentRouted;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;
use App\Notifications\DocumentAwaitingAction;
use App\Notifications\DocumentCompletedNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class DocumentRoutingService
{
    public function __construct(private DocumentService $docs) {}

    /**
     * @param  array<int, array{user_id:int, action_required:\App\Enums\DocumentRouteAction|string, due_at?:?string}>  $recipients
     */
    public function route(Document $doc, array $recipients): void
    {
        if ($doc->status !== DocumentStatus::Draft) {
            throw new InvalidArgumentException('Only draft documents can be routed.');
        }
        if (empty($recipients)) {
            throw new InvalidArgumentException('At least one recipient is required.');
        }

        DB::transaction(function () use ($doc, $recipients) {
            foreach ($recipients as $i => $r) {
                DocumentRoute::create([
                    'document_id'     => $doc->id,
                    'version_id'      => $doc->current_version_id,
                    'sequence'        => $i + 1,
                    'from_user_id'    => $i === 0 ? $doc->owner_id
                                                  : $recipients[$i - 1]['user_id'],
                    'to_user_id'      => $r['user_id'],
                    'action_required' => $r['action_required'] instanceof \App\Enums\DocumentRouteAction
                                          ? $r['action_required']->value
                                          : $r['action_required'],
                    'status'          => $i === 0
                                          ? DocumentRouteStatus::InProgress->value
                                          : DocumentRouteStatus::Pending->value,
                    'due_at'          => $r['due_at'] ?? null,
                ]);
            }

            $doc->update(['status' => DocumentStatus::InReview]);

            $first = $doc->routes()->orderBy('sequence')->first();
            $this->docs->logEvent($doc, $doc->owner, DocumentEventType::Routed, [
                'route_count' => count($recipients),
                'first_route' => $first->id,
            ]);

            Notification::send($first->toUser, new DocumentAwaitingAction($doc, $first));
            event(new DocumentRouted($doc, $first));
        });
    }

    public function act(DocumentRoute $route, string $decision, ?string $comment, User $by): void
    {
        if ($route->status !== DocumentRouteStatus::InProgress) {
            throw new InvalidArgumentException('Route is not awaiting action.');
        }
        if (! in_array($decision, ['complete', 'reject'], true)) {
            throw new InvalidArgumentException("Unknown decision: {$decision}");
        }

        DB::transaction(function () use ($route, $decision, $comment, $by) {
            $doc = $route->document;

            if ($decision === 'reject') {
                $route->update([
                    'status'   => DocumentRouteStatus::Rejected,
                    'acted_at' => now(),
                    'comment'  => $comment,
                ]);
                $doc->routes()
                    ->where('sequence', '>', $route->sequence)
                    ->update(['status' => DocumentRouteStatus::Cancelled->value]);
                $doc->update(['status' => DocumentStatus::Rejected]);

                $this->docs->logEvent($doc, $by, DocumentEventType::Rejected, [
                    'route_id' => $route->id,
                    'comment'  => $comment,
                ]);

                Notification::send($doc->owner, new DocumentCompletedNotice($doc, rejected: true));
                event(new DocumentRejected($doc, $route));
                return;
            }

            // complete
            $route->update([
                'status'   => DocumentRouteStatus::Completed,
                'acted_at' => now(),
                'comment'  => $comment,
            ]);

            $next = $doc->routes()
                ->where('sequence', '>', $route->sequence)
                ->orderBy('sequence')
                ->first();

            if ($next) {
                $next->update(['status' => DocumentRouteStatus::InProgress->value]);
                $this->docs->logEvent($doc, $by, DocumentEventType::Forwarded, [
                    'from_route' => $route->id,
                    'to_route'   => $next->id,
                ]);
                Notification::send($next->toUser, new DocumentAwaitingAction($doc, $next));
                return;
            }

            $doc->update(['status' => DocumentStatus::Completed]);
            $this->docs->logEvent($doc, $by, DocumentEventType::Completed, [
                'route_id' => $route->id,
            ]);
            Notification::send($doc->owner, new DocumentCompletedNotice($doc, rejected: false));
            event(new DocumentCompleted($doc));
        });
    }

    public function withdraw(Document $doc, User $by): void
    {
        if ($doc->status !== DocumentStatus::InReview) {
            throw new InvalidArgumentException('Only in-review documents can be withdrawn.');
        }

        DB::transaction(function () use ($doc, $by) {
            $doc->routes()
                ->whereIn('status', [
                    DocumentRouteStatus::InProgress->value,
                    DocumentRouteStatus::Pending->value,
                ])
                ->update(['status' => DocumentRouteStatus::Cancelled->value]);

            $doc->update(['status' => DocumentStatus::Withdrawn]);
            $this->docs->logEvent($doc, $by, DocumentEventType::Withdrawn);
        });
    }
}
```

- [ ] **Step 4: Create stub Events + Notifications (so service can be loaded)**

`app/Events/DocumentRouted.php`:
```php
<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentRoute;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentRouted
{
    use Dispatchable;
    public function __construct(public Document $document, public DocumentRoute $route) {}
}
```

`app/Events/DocumentSigned.php`:
```php
<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentSigned
{
    use Dispatchable;
    public function __construct(public Document $document, public DocumentAnnotation $annotation) {}
}
```

`app/Events/DocumentCompleted.php`:
```php
<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentCompleted
{
    use Dispatchable;
    public function __construct(public Document $document) {}
}
```

`app/Events/DocumentRejected.php`:
```php
<?php

namespace App\Events;

use App\Models\Document;
use App\Models\DocumentRoute;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentRejected
{
    use Dispatchable;
    public function __construct(public Document $document, public DocumentRoute $route) {}
}
```

`app/Notifications/DocumentAwaitingAction.php`:
```php
<?php

namespace App\Notifications;

use App\Models\Document;
use App\Models\DocumentRoute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentAwaitingAction extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Document $document, public DocumentRoute $route) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Document awaiting your action: {$this->document->ref_no}")
            ->line("'{$this->document->title}' is awaiting your action.")
            ->action('Open document', url("/documents/{$this->document->uuid}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'document_id' => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'ref_no'      => $this->document->ref_no,
            'title'       => $this->document->title,
            'route_id'    => $this->route->id,
            'action'      => $this->route->action_required->value,
        ];
    }
}
```

`app/Notifications/DocumentCompletedNotice.php`:
```php
<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentCompletedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Document $document, public bool $rejected = false) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verb = $this->rejected ? 'rejected' : 'completed';
        return (new MailMessage)
            ->subject("Document {$verb}: {$this->document->ref_no}")
            ->line("'{$this->document->title}' has been {$verb}.")
            ->action('Open document', url("/documents/{$this->document->uuid}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'document_id'   => $this->document->id,
            'document_uuid' => $this->document->uuid,
            'ref_no'        => $this->document->ref_no,
            'title'         => $this->document->title,
            'rejected'      => $this->rejected,
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test --filter=DocumentRoutingServiceTest
```
Expected: 5 passing tests.

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/Services/DocumentRoutingServiceTest.php app/Services/DocumentRoutingService.php app/Events/Document*.php app/Notifications/Document*.php database/factories/Document*.php
git commit -m "feat(documents): add DocumentRoutingService (route, act, withdraw) + events + notifications"
```

---

## Task 8: DocumentRenderService

**Files:**
- Create: `app/Services/DocumentRenderService.php`

- [ ] **Step 1: Create `DocumentRenderService`**

```php
<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;

class DocumentRenderService
{
    private const DISK = 'local';

    /**
     * Render a version with all annotations burned in. Returns absolute path to the PDF.
     * Cached per (version_id, annotation_set_hash).
     */
    public function burn(DocumentVersion $version): string
    {
        $doc = $version->document;
        $annotations = $doc->annotations()
            ->where('version_id', $version->id)
            ->orderBy('page')
            ->get();

        $hash = $this->annotationHash($annotations);
        $cachePath = sprintf('documents/%s/v%d/burned-%s.pdf', $doc->uuid, $version->version_no, $hash);

        if (Storage::disk(self::DISK)->exists($cachePath)) {
            return Storage::disk(self::DISK)->path($cachePath);
        }

        $sourcePath = Storage::disk(self::DISK)->path($version->storage_path);

        // Image source → wrap into a single-page PDF first.
        if (str_starts_with($version->mime, 'image/')) {
            $sourcePath = $this->imageToPdf($sourcePath);
        } elseif ($version->mime !== 'application/pdf') {
            // DOCX et al. — burn-in unsupported in v1; return the original.
            return Storage::disk(self::DISK)->path($version->storage_path);
        }

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($sourcePath);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tplId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tplId);
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId);

            $pageWidth  = $size['width'];
            $pageHeight = $size['height'];

            foreach ($annotations->where('page', $pageNo) as $a) {
                $this->drawAnnotation($pdf, $a, $pageWidth, $pageHeight);
            }
        }

        $absolutePath = Storage::disk(self::DISK)->path($cachePath);
        @mkdir(dirname($absolutePath), 0775, true);
        $pdf->Output($absolutePath, 'F');

        return $absolutePath;
    }

    /**
     * Wrap a single image into a 1-page PDF and return its absolute path.
     */
    public function imageToPdf(string $absoluteImagePath): string
    {
        [$w, $h] = getimagesize($absoluteImagePath);
        // A4 portrait if image is portrait-shaped, landscape otherwise.
        $orientation = $w > $h ? 'L' : 'P';
        $pdf = new \TCPDF($orientation, 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pageW = $pdf->getPageWidth() - 20;
        $pageH = $pdf->getPageHeight() - 20;
        $pdf->Image($absoluteImagePath, 10, 10, $pageW, $pageH, '', '', '', false, 300, '', false, false, 0, 'CT');

        $out = tempnam(sys_get_temp_dir(), 'doc') . '.pdf';
        $pdf->Output($out, 'F');
        return $out;
    }

    private function drawAnnotation(Fpdi $pdf, $annotation, float $pageW, float $pageH): void
    {
        $x = $pageW * ($annotation->x_pct / 100);
        $y = $pageH * ($annotation->y_pct / 100);
        $w = $pageW * ($annotation->w_pct / 100);
        $h = $pageH * ($annotation->h_pct / 100);

        $data = $annotation->data ?? [];

        if ($annotation->type->value === 'signature' || $annotation->type->value === 'initial') {
            $png = $data['png_base64'] ?? null;
            if ($png) {
                $tmp = tempnam(sys_get_temp_dir(), 'sig') . '.png';
                file_put_contents($tmp, base64_decode(preg_replace('#^data:image/png;base64,#', '', $png)));
                $pdf->Image($tmp, $x, $y, $w, $h, 'PNG');
                @unlink($tmp);
            }
            return;
        }

        if ($annotation->type->value === 'stamp') {
            if (! empty($data['png_base64'])) {
                $tmp = tempnam(sys_get_temp_dir(), 'stp') . '.png';
                file_put_contents($tmp, base64_decode(preg_replace('#^data:image/png;base64,#', '', $data['png_base64'])));
                $pdf->Image($tmp, $x, $y, $w, $h, 'PNG');
                @unlink($tmp);
                return;
            }
            // text stamp
            $text  = $data['text']  ?? 'STAMP';
            $color = $data['color'] ?? '#cc0000';
            [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
            $pdf->SetTextColor($r, $g, $b);
            $pdf->SetDrawColor($r, $g, $b);
            $pdf->SetLineWidth(0.8);
            $pdf->Rect($x, $y, $w, $h);
            $pdf->SetFont('helvetica', 'B', max(8, $h * 2.5));
            $pdf->SetXY($x, $y + ($h / 4));
            $pdf->Cell($w, $h, $text, 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetDrawColor(0, 0, 0);
            return;
        }

        if ($annotation->type->value === 'text') {
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x, $y);
            $pdf->SetFont('helvetica', '', max(8, $h * 2));
            $pdf->MultiCell($w, $h, $data['text'] ?? '', 0, 'L');
        }
    }

    private function annotationHash($annotations): string
    {
        $payload = $annotations->map(fn ($a) => [
            'id'   => $a->id,
            'type' => $a->type->value,
            'page' => $a->page,
            'x'    => $a->x_pct,
            'y'    => $a->y_pct,
            'w'    => $a->w_pct,
            'h'    => $a->h_pct,
            'data' => $a->data,
        ])->toJson();
        return substr(hash('sha256', $payload), 0, 16);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/DocumentRenderService.php
git commit -m "feat(documents): add DocumentRenderService (PDF burn-in via FPDI + TCPDF)"
```

---

## Task 9: DocumentConversionService

**Files:**
- Create: `app/Services/DocumentConversionService.php`

- [ ] **Step 1: Create `DocumentConversionService`**

```php
<?php

namespace App\Services;

use App\Exceptions\ConversionNotSupportedException;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;

class DocumentConversionService
{
    private const DISK = 'local';

    public function __construct(private DocumentRenderService $render) {}

    /**
     * Convert a version to the requested format. Returns absolute output path.
     *
     * Supported v1: image → pdf
     * Unsupported v1: docx → pdf, pdf → docx, anything else
     */
    public function convert(DocumentVersion $version, string $to): string
    {
        $to = strtolower($to);

        if ($to !== 'pdf') {
            throw ConversionNotSupportedException::forFormat($version->mime, $to);
        }

        if ($version->mime === 'application/pdf') {
            return Storage::disk(self::DISK)->path($version->storage_path);
        }

        if (str_starts_with($version->mime, 'image/')) {
            return $this->render->imageToPdf(Storage::disk(self::DISK)->path($version->storage_path));
        }

        // DOCX / other office formats — defer
        throw ConversionNotSupportedException::forFormat($version->mime, $to);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/DocumentConversionService.php
git commit -m "feat(documents): add DocumentConversionService (image→PDF stub)"
```

---

## Task 10: FormRequests

**Files:**
- Create: `app/Http/Requests/Documents/StoreDocumentRequest.php`
- Create: `app/Http/Requests/Documents/AddVersionRequest.php`
- Create: `app/Http/Requests/Documents/RouteDocumentRequest.php`
- Create: `app/Http/Requests/Documents/AnnotateDocumentRequest.php`
- Create: `app/Http/Requests/Documents/ActOnRouteRequest.php`

- [ ] **Step 1: `StoreDocumentRequest`**

```php
<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentConfidentiality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Document::class);
    }

    public function rules(): array
    {
        return [
            'title'           => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:2000'],
            'confidentiality' => ['nullable', new Enum(DocumentConfidentiality::class)],
            'tags'            => ['nullable', 'array'],
            'tags.*'          => ['string', 'max:40'],
            'file'            => ['required', 'file', 'max:25600', 'mimes:pdf,docx,doc,png,jpg,jpeg'],
        ];
    }
}
```

- [ ] **Step 2: `AddVersionRequest`**

```php
<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class AddVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'file'  => ['required', 'file', 'max:25600', 'mimes:pdf,docx,doc,png,jpg,jpeg'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

- [ ] **Step 3: `RouteDocumentRequest`**

```php
<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentRouteAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class RouteDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('route', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'recipients'                    => ['required', 'array', 'min:1'],
            'recipients.*.user_id'          => ['required', 'integer', 'exists:users,id', 'different:'.$this->user()->id],
            'recipients.*.action_required'  => ['required', new Enum(DocumentRouteAction::class)],
            'recipients.*.due_at'           => ['nullable', 'date', 'after:now'],
        ];
    }
}
```

- [ ] **Step 4: `AnnotateDocumentRequest`**

```php
<?php

namespace App\Http\Requests\Documents;

use App\Enums\DocumentAnnotationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AnnotateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('annotate', $this->route('document'));
    }

    public function rules(): array
    {
        return [
            'type'     => ['required', new Enum(DocumentAnnotationType::class)],
            'page'     => ['required', 'integer', 'min:1'],
            'x_pct'    => ['required', 'numeric', 'between:0,100'],
            'y_pct'    => ['required', 'numeric', 'between:0,100'],
            'w_pct'    => ['nullable', 'numeric', 'between:0.5,100'],
            'h_pct'    => ['nullable', 'numeric', 'between:0.5,100'],
            'rotation' => ['nullable', 'integer', 'between:-180,180'],
            'data'     => ['required', 'array'],
            'data.png_base64' => ['nullable', 'string'],
            'data.svg'        => ['nullable', 'string'],
            'data.text'       => ['nullable', 'string', 'max:500'],
            'data.color'      => ['nullable', 'string', 'max:9'],
        ];
    }
}
```

- [ ] **Step 5: `ActOnRouteRequest`**

```php
<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActOnRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('act', [
            $this->route('document'),
            $this->route('route'),
        ]);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['complete', 'reject'])],
            'comment'  => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('decision') === 'reject')],
        ];
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/Documents
git commit -m "feat(documents): add form requests (store, version, route, annotate, act)"
```

---

## Task 11: Resources

**Files:**
- Create: `app/Http/Resources/DocumentResource.php`
- Create: `app/Http/Resources/DocumentRouteResource.php`
- Create: `app/Http/Resources/DocumentAnnotationResource.php`
- Create: `app/Http/Resources/DocumentEventResource.php`

- [ ] **Step 1: `DocumentResource`**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'ref_no'          => $this->ref_no,
            'title'           => $this->title,
            'description'     => $this->description,
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'confidentiality' => $this->confidentiality?->value,
            'tags'            => $this->tags ?? [],
            'owner'           => $this->whenLoaded('owner', fn () => [
                'id'   => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'current_version' => $this->whenLoaded('currentVersion', fn () => [
                'id'           => $this->currentVersion?->id,
                'version_no'   => $this->currentVersion?->version_no,
                'original_name'=> $this->currentVersion?->original_name,
                'mime'         => $this->currentVersion?->mime,
                'size'         => $this->currentVersion?->size,
            ]),
            'routes'      => DocumentRouteResource::collection($this->whenLoaded('routes')),
            'annotations' => DocumentAnnotationResource::collection($this->whenLoaded('annotations')),
            'events'      => DocumentEventResource::collection($this->whenLoaded('events')),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
```

- [ ] **Step 2: `DocumentRouteResource`**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRouteResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'sequence'        => $this->sequence,
            'from_user'       => ['id' => $this->from_user_id, 'name' => $this->fromUser?->name],
            'to_user'         => ['id' => $this->to_user_id,   'name' => $this->toUser?->name],
            'action_required' => $this->action_required?->value,
            'action_label'    => $this->action_required?->label(),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'due_at'          => $this->due_at,
            'acted_at'        => $this->acted_at,
            'comment'         => $this->comment,
        ];
    }
}
```

- [ ] **Step 3: `DocumentAnnotationResource`**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentAnnotationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'        => $this->id,
            'type'      => $this->type?->value,
            'page'      => $this->page,
            'x_pct'     => (float) $this->x_pct,
            'y_pct'     => (float) $this->y_pct,
            'w_pct'     => (float) $this->w_pct,
            'h_pct'     => (float) $this->h_pct,
            'rotation'  => $this->rotation,
            'data'      => $this->data,
            'user'      => ['id' => $this->user_id, 'name' => $this->user?->name],
            'route_id'  => $this->route_id,
            'created_at'=> $this->created_at,
        ];
    }
}
```

- [ ] **Step 4: `DocumentEventResource`**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DocumentEventResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type?->value,
            'actor'        => ['id' => $this->actor_id, 'name' => $this->actor?->name],
            'payload'      => $this->payload,
            'occurred_at'  => $this->occurred_at,
        ];
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/Document*.php
git commit -m "feat(documents): add JSON resources (Document, Route, Annotation, Event)"
```

---

## Task 12: DocumentController

**Files:**
- Create: `app/Http/Controllers/DocumentController.php`

- [ ] **Step 1: Create `DocumentController`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\ConversionNotSupportedException;
use App\Http\Requests\Documents\ActOnRouteRequest;
use App\Http\Requests\Documents\AddVersionRequest;
use App\Http\Requests\Documents\AnnotateDocumentRequest;
use App\Http\Requests\Documents\RouteDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Resources\DocumentAnnotationResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Services\DocumentConversionService;
use App\Services\DocumentRenderService;
use App\Services\DocumentRoutingService;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function __construct(
        private DocumentService $docs,
        private DocumentRoutingService $routing,
        private DocumentRenderService $render,
        private DocumentConversionService $conversion,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);
        $user = $request->user();
        $tab  = $request->string('tab', 'all')->toString();

        $base = Document::query()->with(['owner', 'currentVersion']);

        $base = match ($tab) {
            'inbox'   => $base->whereHas('routes', fn ($q) => $q
                            ->where('to_user_id', $user->id)
                            ->where('status', DocumentRouteStatus::InProgress->value)),
            'sent'    => $base->where('owner_id', $user->id)
                              ->whereIn('status', [DocumentStatus::InReview->value, DocumentStatus::Completed->value]),
            'drafts'  => $base->where('owner_id', $user->id)
                              ->where('status', DocumentStatus::Draft->value),
            'archive' => $base->where('status', DocumentStatus::Archived->value),
            default   => $base->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                  ->orWhereHas('routes', fn ($r) => $r->where('to_user_id', $user->id));
            }),
        };

        if ($q = $request->string('q')->toString()) {
            $base->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                  ->orWhere('ref_no', 'like', "%{$q}%");
            });
        }

        $documents = $base->latest()->paginate(20)->withQueryString();

        $inboxCount = DocumentRoute::query()
            ->where('to_user_id', $user->id)
            ->where('status', DocumentRouteStatus::InProgress->value)
            ->count();

        return Inertia::render('Documents/Index', [
            'documents'    => DocumentResource::collection($documents),
            'tab'          => $tab,
            'filters'      => ['q' => $request->string('q')->toString()],
            'inboxCount'   => $inboxCount,
            'activeModule' => 'documents',
        ]);
    }

    public function store(StoreDocumentRequest $request)
    {
        $doc = $this->docs->upload(
            $request->file('file'),
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('documents.show', $doc->uuid)
            ->with('flash.success', "Document {$doc->ref_no} uploaded.");
    }

    public function show(Document $document): Response
    {
        $this->authorize('view', $document);

        $document->load([
            'owner', 'currentVersion', 'versions',
            'routes.fromUser', 'routes.toUser',
            'annotations.user',
            'events.actor',
        ]);

        return Inertia::render('Documents/Show', [
            'document'     => new DocumentResource($document),
            'activeModule' => 'documents',
        ]);
    }

    public function addVersion(AddVersionRequest $request, Document $document)
    {
        $this->docs->addVersion(
            $document,
            $request->file('file'),
            $request->user(),
            $request->string('notes')->toString() ?: null,
        );

        return back()->with('flash.success', 'New version uploaded.');
    }

    public function route(RouteDocumentRequest $request, Document $document)
    {
        $this->routing->route($document, $request->validated('recipients'));
        return back()->with('flash.success', 'Document routed.');
    }

    public function annotate(AnnotateDocumentRequest $request, Document $document)
    {
        $activeRoute = $document->routes()
            ->where('to_user_id', $request->user()->id)
            ->where('status', DocumentRouteStatus::InProgress->value)
            ->first();

        $annotation = $this->docs->saveAnnotation($document, $activeRoute, $request->user(), $request->validated());

        return back()->with([
            'flash.success' => 'Annotation saved.',
            'annotation'    => (new DocumentAnnotationResource($annotation))->resolve(),
        ]);
    }

    public function removeAnnotation(Document $document, int $annotationId, Request $request)
    {
        $this->authorize('annotate', $document);
        $a = $document->annotations()->where('id', $annotationId)->firstOrFail();
        abort_unless($a->user_id === $request->user()->id, 403);
        $this->docs->removeAnnotation($a, $request->user());
        return back()->with('flash.success', 'Annotation removed.');
    }

    public function act(ActOnRouteRequest $request, Document $document, DocumentRoute $route)
    {
        $this->routing->act(
            $route,
            $request->validated('decision'),
            $request->validated('comment'),
            $request->user(),
        );

        return back()->with('flash.success', 'Decision recorded.');
    }

    public function withdraw(Document $document, Request $request)
    {
        $this->authorize('withdraw', $document);
        $this->routing->withdraw($document, $request->user());
        return back()->with('flash.success', 'Document withdrawn.');
    }

    public function archive(Document $document, Request $request)
    {
        $this->authorize('manage', Document::class);
        $this->docs->archive($document, $request->user());
        return back()->with('flash.success', 'Document archived.');
    }

    public function download(Document $document, Request $request): BinaryFileResponse
    {
        $this->authorize('view', $document);

        $burned = $request->boolean('burned', false);
        $version = $request->integer('version')
            ? $document->versions()->where('version_no', $request->integer('version'))->firstOrFail()
            : $document->currentVersion;

        abort_unless($version, 404);

        $path = $burned
            ? $this->render->burn($version)
            : Storage::disk('local')->path($version->storage_path);

        $name = $burned
            ? "{$document->ref_no}-burned.pdf"
            : $version->original_name;

        return response()->download($path, $name);
    }

    public function convert(Document $document, Request $request)
    {
        $this->authorize('view', $document);
        $to = $request->string('to', 'pdf')->toString();

        try {
            $path = $this->conversion->convert($document->currentVersion, $to);
            return response()->download($path, $document->ref_no . '.' . $to);
        } catch (ConversionNotSupportedException $e) {
            return response()->json(['message' => $e->getMessage()], 501);
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/DocumentController.php
git commit -m "feat(documents): add DocumentController (index, store, show, route, annotate, act, download, convert)"
```

---

## Task 13: Routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Add document routes**

Open `routes/web.php` and append inside the `Route::middleware(['auth', 'audit'])->group(...)` block:

```php
use App\Http\Controllers\DocumentController;

// Documents
Route::prefix('documents')->name('documents.')->group(function () {
    Route::get('/',                            [DocumentController::class, 'index'])->name('index');
    Route::post('/',                           [DocumentController::class, 'store'])->name('store');
    Route::get('/{document}',                  [DocumentController::class, 'show'])->name('show');
    Route::post('/{document}/versions',        [DocumentController::class, 'addVersion'])->name('versions.store');
    Route::post('/{document}/route',           [DocumentController::class, 'route'])->name('route');
    Route::post('/{document}/withdraw',        [DocumentController::class, 'withdraw'])->name('withdraw');
    Route::post('/{document}/archive',         [DocumentController::class, 'archive'])->name('archive');
    Route::post('/{document}/annotations',     [DocumentController::class, 'annotate'])->name('annotations.store');
    Route::delete('/{document}/annotations/{annotationId}', [DocumentController::class, 'removeAnnotation'])->name('annotations.destroy');
    Route::post('/{document}/routes/{route}/act', [DocumentController::class, 'act'])->name('routes.act');
    Route::get('/{document}/download',         [DocumentController::class, 'download'])->name('download');
    Route::post('/{document}/convert',         [DocumentController::class, 'convert'])->name('convert');
});
```

- [ ] **Step 2: Verify route list**

```bash
php artisan route:list --name=documents
```
Expected: 12 named routes listed.

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat(documents): wire up document routes"
```

---

## Task 14: Sidebar nav entry

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue`

- [ ] **Step 1: Add Documents to `navSections`**

Open `resources/js/Layouts/AuthenticatedLayout.vue`. Locate the `navSections` computed (around lines 60-150). Inside the section that lists module entries (e.g., "Operations" or "Workspaces"), add:

```javascript
{
  label:   'Documents',
  route:   'documents.index',
  module:  'documents',
  icon:    'description',
  visible: can('documents.view'),
  badge:   $page.props.inboxCount ?? 0,   // optional badge
}
```

If the existing pattern doesn't have a `badge` field, just add `label`/`route`/`module`/`icon`/`visible` for now; we'll wire the badge in a later iteration.

- [ ] **Step 2: Build and verify**

```bash
npm run build
```
Expected: build passes; new route + sidebar entry visible after login.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "feat(documents): add Documents to sidebar nav"
```

---

## Task 15: Vue — Viewer + AnnotationLayer

**Files:**
- Create: `resources/js/Components/Documents/Viewer.vue`
- Create: `resources/js/Components/Documents/AnnotationLayer.vue`

- [ ] **Step 1: `Viewer.vue`**

```vue
<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import * as pdfjs from 'pdfjs-dist';
import workerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerSrc;

const props = defineProps({
    /** Signed URL to the source file */
    src:      { type: String, required: true },
    /** mime type of the source */
    mime:     { type: String, required: true },
    /** Optional page selection from parent */
    page:     { type: Number, default: 1 },
});

const emit = defineEmits(['rendered', 'page-changed', 'page-size']);

const containerRef = ref(null);
const pdfDoc       = ref(null);
const totalPages   = ref(1);
const currentPage  = ref(props.page);
const pageSize     = ref({ width: 0, height: 0 });

const isPdf   = () => props.mime === 'application/pdf';
const isImage = () => props.mime?.startsWith('image/');

async function renderPdf() {
    const loadingTask = pdfjs.getDocument(props.src);
    pdfDoc.value = await loadingTask.promise;
    totalPages.value = pdfDoc.value.numPages;
    await renderPage(currentPage.value);
}

async function renderPage(num) {
    if (! pdfDoc.value) return;
    const page = await pdfDoc.value.getPage(num);
    const viewport = page.getViewport({ scale: 1.4 });

    const canvas = containerRef.value.querySelector('canvas');
    canvas.width  = viewport.width;
    canvas.height = viewport.height;
    pageSize.value = { width: viewport.width, height: viewport.height };
    emit('page-size', pageSize.value);

    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    emit('rendered', { page: num });
}

function gotoPage(num) {
    if (num < 1 || num > totalPages.value) return;
    currentPage.value = num;
    emit('page-changed', num);
    renderPage(num);
}

onMounted(() => {
    if (isPdf()) renderPdf();
});

watch(() => props.src, () => {
    if (isPdf()) renderPdf();
});
</script>

<template>
    <div class="relative w-full">
        <div v-if="isImage()" class="flex items-center justify-center bg-surface-container-low rounded-xl border border-outline-variant/40 p-4">
            <img :src="src" alt="Document"
                 class="max-w-full max-h-[80vh] rounded-md shadow"
                 @load="(e) => emit('page-size', { width: e.target.naturalWidth, height: e.target.naturalHeight })" />
        </div>

        <div v-else-if="isPdf()" ref="containerRef" class="relative inline-block">
            <canvas class="rounded-xl border border-outline-variant/40 shadow"></canvas>
            <slot name="overlay" :pageSize="pageSize" :page="currentPage" />

            <div class="mt-3 flex items-center gap-3 text-[12px] font-semibold">
                <button @click="gotoPage(currentPage - 1)" :disabled="currentPage <= 1"
                        class="rounded-lg border border-outline-variant px-3 py-1.5 disabled:opacity-40">Prev</button>
                <span>Page {{ currentPage }} / {{ totalPages }}</span>
                <button @click="gotoPage(currentPage + 1)" :disabled="currentPage >= totalPages"
                        class="rounded-lg border border-outline-variant px-3 py-1.5 disabled:opacity-40">Next</button>
            </div>
        </div>

        <div v-else class="rounded-xl border border-outline-variant/40 bg-surface-container-low p-8 text-center text-on-surface-variant">
            <span class="material-symbols-outlined text-[40px]">description</span>
            <p class="mt-2 text-[13px] font-semibold">Preview not available for this format. Use Download to view.</p>
        </div>
    </div>
</template>
```

- [ ] **Step 2: `AnnotationLayer.vue`**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    annotations: { type: Array, default: () => [] },
    page:        { type: Number, default: 1 },
    pageSize:    { type: Object, required: true },     // { width, height }
    canPlace:    { type: Boolean, default: false },
    pending:     { type: Object, default: null },      // { type, data, w_pct, h_pct }
});

const emit = defineEmits(['place', 'remove']);

const visible = computed(() => props.annotations.filter(a => a.page === props.page));

function handleClick(e) {
    if (! props.canPlace || ! props.pending) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const x_pct = ((e.clientX - rect.left) / rect.width)  * 100;
    const y_pct = ((e.clientY - rect.top)  / rect.height) * 100;
    emit('place', { x_pct, y_pct, page: props.page });
}
</script>

<template>
    <div class="absolute inset-0"
         :class="canPlace ? 'cursor-crosshair' : 'pointer-events-none'"
         @click="handleClick">
        <div v-for="a in visible" :key="a.id"
             :style="{
                 position: 'absolute',
                 left:   a.x_pct + '%',
                 top:    a.y_pct + '%',
                 width:  a.w_pct + '%',
                 height: a.h_pct + '%',
             }"
             class="group pointer-events-auto">
            <img v-if="a.type === 'signature' || a.type === 'initial' || (a.type === 'stamp' && a.data?.png_base64)"
                 :src="a.data.png_base64"
                 class="w-full h-full object-contain" />
            <div v-else-if="a.type === 'stamp'"
                 class="flex items-center justify-center w-full h-full border-2 font-black text-center"
                 :style="{ color: a.data?.color ?? '#cc0000', borderColor: a.data?.color ?? '#cc0000' }">
                {{ a.data?.text ?? 'STAMP' }}
            </div>
            <div v-else-if="a.type === 'text'"
                 class="text-[11px] font-semibold text-on-surface">
                {{ a.data?.text }}
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Build to verify**

```bash
npm run build
```
Expected: build passes.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Documents/Viewer.vue resources/js/Components/Documents/AnnotationLayer.vue
git commit -m "feat(documents): add Viewer (pdf.js) + AnnotationLayer components"
```

---

## Task 16: Vue — SignaturePad + StampPicker

**Files:**
- Create: `resources/js/Components/Documents/SignaturePad.vue`
- Create: `resources/js/Components/Documents/StampPicker.vue`

- [ ] **Step 1: `SignaturePad.vue`**

```vue
<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import SignaturePad from 'signature_pad';

const emit = defineEmits(['signed', 'cancel']);

const canvasRef = ref(null);
let pad = null;

onMounted(() => {
    pad = new SignaturePad(canvasRef.value, {
        penColor:       '#0d1452',
        backgroundColor:'#fff',
        minWidth: 0.6,
        maxWidth: 2.5,
    });
});

onBeforeUnmount(() => { pad?.off(); });

function clear() { pad?.clear(); }

function save() {
    if (pad.isEmpty()) {
        alert('Please draw a signature first.');
        return;
    }
    const png = pad.toDataURL('image/png');
    emit('signed', { png_base64: png });
}
</script>

<template>
    <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-lg">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Add your signature</p>
            <h2 class="text-lg font-black text-primary leading-tight mb-3">Draw signature</h2>
            <canvas ref="canvasRef" width="500" height="200" class="block w-full border border-outline-variant rounded-lg bg-white"></canvas>
            <div class="flex items-center justify-between mt-4">
                <button @click="clear" class="text-[12px] font-bold text-on-surface-variant hover:text-primary">Clear</button>
                <div class="flex items-center gap-2">
                    <button @click="emit('cancel')" class="rounded-lg border border-outline-variant px-4 py-2 text-[13px] font-bold">Cancel</button>
                    <button @click="save" class="rounded-lg px-4 py-2 text-[13px] font-black text-white" style="background:linear-gradient(135deg,#0d1452,#1a237e)">Save</button>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: `StampPicker.vue`**

```vue
<script setup>
import { ref } from 'vue';

const props = defineProps({
    presets: {
        type: Array,
        default: () => [
            { text: 'APPROVED',     color: '#059669' },
            { text: 'RECEIVED',     color: '#1a237e' },
            { text: 'FOR ACTION',   color: '#d97706' },
            { text: 'CONFIDENTIAL', color: '#dc2626' },
            { text: 'COPY',         color: '#64748b' },
        ],
    },
});

const emit = defineEmits(['stamp', 'cancel']);

const customText  = ref('');
const customColor = ref('#cc0000');
</script>

<template>
    <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-md">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Place a stamp</p>
            <h2 class="text-lg font-black text-primary leading-tight mb-3">Choose a stamp</h2>

            <div class="grid grid-cols-2 gap-2">
                <button v-for="p in presets" :key="p.text"
                        @click="emit('stamp', { text: p.text, color: p.color })"
                        class="flex items-center justify-center border-2 rounded-lg px-3 py-3 text-[13px] font-black"
                        :style="{ color: p.color, borderColor: p.color }">
                    {{ p.text }}
                </button>
            </div>

            <div class="mt-4 border-t border-outline-variant/40 pt-3">
                <p class="text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-2">Custom</p>
                <div class="flex items-center gap-2">
                    <input v-model="customText" placeholder="STAMP TEXT" maxlength="20"
                           class="flex-1 rounded-lg border border-outline-variant text-[13px] px-3 py-2 font-bold uppercase" />
                    <input v-model="customColor" type="color" class="rounded-lg border border-outline-variant w-10 h-10" />
                    <button :disabled="! customText.trim()"
                            @click="emit('stamp', { text: customText.trim().toUpperCase(), color: customColor })"
                            class="rounded-lg px-3 py-2 text-[12px] font-black text-white disabled:opacity-40"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)">Use</button>
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button @click="emit('cancel')" class="rounded-lg border border-outline-variant px-4 py-2 text-[13px] font-bold">Cancel</button>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Documents/SignaturePad.vue resources/js/Components/Documents/StampPicker.vue
git commit -m "feat(documents): add SignaturePad + StampPicker components"
```

---

## Task 17: Vue — RoutingSlipPanel + TimelineRail

**Files:**
- Create: `resources/js/Components/Documents/RoutingSlipPanel.vue`
- Create: `resources/js/Components/Documents/TimelineRail.vue`

- [ ] **Step 1: `RoutingSlipPanel.vue`**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    routes: { type: Array, default: () => [] },
});

const sorted = computed(() => [...props.routes].sort((a, b) => a.sequence - b.sequence));

const stepCls = (s) => ({
    completed:  'bg-emerald-50 text-emerald-800 border-emerald-300',
    in_progress:'bg-amber-50 text-amber-900 border-amber-400',
    pending:    'bg-surface-container-low text-on-surface-variant border-outline-variant',
    rejected:   'bg-rose-50 text-rose-800 border-rose-300',
    cancelled:  'bg-slate-100 text-slate-500 border-slate-300',
}[s] ?? 'bg-surface-container-low text-on-surface-variant border-outline-variant');
</script>

<template>
    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-3">Routing slip</p>
        <div v-if="!sorted.length" class="text-[12px] font-semibold text-on-surface-variant">
            Not routed yet.
        </div>
        <ol v-else class="space-y-2">
            <li v-for="r in sorted" :key="r.id"
                class="rounded-xl border px-3 py-2 text-[12px] font-semibold flex items-center gap-3"
                :class="stepCls(r.status)">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white border border-current font-black text-[11px]">
                    {{ r.sequence }}
                </span>
                <div class="flex-1 min-w-0">
                    <div class="truncate font-black">{{ r.to_user?.name ?? '—' }}</div>
                    <div class="text-[11px] opacity-75">{{ r.action_label }} · {{ r.status_label }}</div>
                </div>
                <div v-if="r.acted_at" class="text-[10px] opacity-60 whitespace-nowrap">
                    {{ new Date(r.acted_at).toLocaleDateString('en-GB') }}
                </div>
            </li>
        </ol>
    </div>
</template>
```

- [ ] **Step 2: `TimelineRail.vue`**

```vue
<script setup>
const props = defineProps({
    events: { type: Array, default: () => [] },
});

const iconFor = {
    uploaded:      'upload_file',
    version_added: 'upload',
    routed:        'send',
    annotated:     'edit_note',
    signed:        'gesture',
    stamped:       'verified',
    forwarded:     'forward',
    rejected:      'block',
    completed:     'check_circle',
    withdrawn:     'undo',
    downloaded:    'download',
    archived:      'archive',
};
</script>

<template>
    <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-3">Timeline</p>
        <div v-if="!events.length" class="text-[12px] font-semibold text-on-surface-variant">
            No activity yet.
        </div>
        <ol v-else class="space-y-3">
            <li v-for="e in events" :key="e.id" class="flex items-start gap-2">
                <span class="material-symbols-outlined text-[18px] text-secondary mt-0.5">{{ iconFor[e.type] ?? 'circle' }}</span>
                <div class="flex-1 min-w-0">
                    <div class="text-[12px] font-black text-primary">{{ e.type.replace('_', ' ') }}</div>
                    <div class="text-[11px] text-on-surface-variant">
                        {{ e.actor?.name ?? '—' }} · {{ new Date(e.occurred_at).toLocaleString('en-GB') }}
                    </div>
                </div>
            </li>
        </ol>
    </div>
</template>
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/Documents/RoutingSlipPanel.vue resources/js/Components/Documents/TimelineRail.vue
git commit -m "feat(documents): add RoutingSlipPanel + TimelineRail components"
```

---

## Task 18: Vue — Documents Index page

**Files:**
- Create: `resources/js/Pages/Documents/Index.vue`

- [ ] **Step 1: Create the page**

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SlidePanel from '@/Components/SlidePanel.vue';

const props = defineProps({
    documents:    Object,
    tab:          String,
    filters:      Object,
    inboxCount:   Number,
    activeModule: String,
});

const TABS = [
    { id: 'all',     label: 'All' },
    { id: 'inbox',   label: 'Inbox' },
    { id: 'sent',    label: 'Sent' },
    { id: 'drafts',  label: 'Drafts' },
    { id: 'archive', label: 'Archive' },
];

const q = ref(props.filters?.q ?? '');

function setTab(id) {
    router.get(route('documents.index'), { tab: id, q: q.value }, { preserveState: true, preserveScroll: true });
}
function search() {
    router.get(route('documents.index'), { tab: props.tab, q: q.value }, { preserveState: true });
}

const showUpload = ref(false);
const form = useForm({ title: '', description: '', confidentiality: 'internal', file: null, tags: [] });

function submit() {
    form.post(route('documents.store'), {
        forceFormData: true,
        onSuccess: () => { showUpload.value = false; form.reset(); },
    });
}

const tone = (status) => ({
    draft:     'bg-slate-100 text-slate-700',
    in_review: 'bg-amber-50 text-amber-900',
    completed: 'bg-emerald-50 text-emerald-800',
    rejected:  'bg-rose-50 text-rose-800',
    withdrawn: 'bg-slate-100 text-slate-500',
    archived:  'bg-slate-100 text-slate-500',
}[status] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <Head title="Documents" />
    <AuthenticatedLayout :activeModule="activeModule">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">description</span>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">DOCUMENT REGISTER</p>
                    </div>
                    <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Documents</h1>
                    <p class="mt-1 text-[13px] font-medium text-on-surface-variant">
                        Upload, route, sign and stamp documents across the institute.
                    </p>
                </div>
                <button @click="showUpload = true"
                        class="btn-shimmer flex items-center gap-2 rounded-xl px-4 py-2.5 text-[13px] font-black text-white shadow-glow-sm transition-all hover:-translate-y-px"
                        style="background:linear-gradient(135deg,#0d1452,#1a237e);">
                    <span class="material-symbols-outlined text-[17px]">upload_file</span>
                    Upload Document
                </button>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Tabs -->
            <div class="inline-flex items-center gap-1 rounded-2xl border border-outline-variant/40 bg-surface-container-lowest p-1 shadow-card">
                <button v-for="t in TABS" :key="t.id" @click="setTab(t.id)"
                        :class="['rounded-xl px-4 py-2 text-[12px] font-black transition-all',
                                 tab === t.id ? 'bg-secondary/10 text-secondary' : 'text-on-surface-variant hover:text-primary']">
                    {{ t.label }}
                    <span v-if="t.id === 'inbox' && inboxCount > 0"
                          class="ml-1.5 inline-flex h-5 min-w-[20px] items-center justify-center rounded-full bg-rose-600 px-1.5 text-[10px] font-black text-white">
                        {{ inboxCount }}
                    </span>
                </button>
            </div>

            <!-- Search -->
            <div class="flex items-center gap-3">
                <input v-model="q" @keyup.enter="search" placeholder="Search title or ref no…"
                       class="flex-1 max-w-md rounded-xl border border-outline-variant bg-surface-container-lowest text-[13px] px-3 py-2 font-semibold" />
                <button @click="search" class="rounded-xl border border-outline-variant bg-surface-container-lowest px-4 py-2 text-[12px] font-black">Search</button>
            </div>

            <!-- List -->
            <div class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="border-b border-outline-variant">
                        <tr class="text-left text-[10px] font-black uppercase tracking-widest text-on-surface-variant">
                            <th class="px-5 py-3">Ref</th>
                            <th class="px-5 py-3">Title</th>
                            <th class="px-5 py-3">Owner</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Updated</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in documents.data" :key="d.id" class="border-b border-outline-variant/40 hover:bg-surface-container-low transition-colors">
                            <td class="px-5 py-3 font-mono text-[12px] font-bold text-primary">{{ d.ref_no }}</td>
                            <td class="px-5 py-3 font-black">{{ d.title }}</td>
                            <td class="px-5 py-3 text-on-surface-variant">{{ d.owner?.name }}</td>
                            <td class="px-5 py-3">
                                <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest', tone(d.status)]">
                                    {{ d.status_label }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-[12px] text-on-surface-variant">{{ new Date(d.updated_at).toLocaleDateString('en-GB') }}</td>
                            <td class="px-5 py-3 text-right">
                                <Link :href="route('documents.show', d.uuid)" class="text-[12px] font-black text-secondary">Open</Link>
                            </td>
                        </tr>
                        <tr v-if="!documents.data?.length">
                            <td colspan="6" class="px-5 py-12 text-center text-on-surface-variant text-[13px]">No documents in this view.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Upload slide panel -->
        <SlidePanel v-if="showUpload" @close="showUpload = false" title="Upload Document">
            <form @submit.prevent="submit" enctype="multipart/form-data" class="space-y-4 p-5">
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Title</label>
                    <input v-model="form.title" required maxlength="255" class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
                    <p v-if="form.errors.title" class="text-rose-600 text-xs mt-1">{{ form.errors.title }}</p>
                </div>
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Description</label>
                    <textarea v-model="form.description" rows="3" class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]"></textarea>
                </div>
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">Confidentiality</label>
                    <select v-model="form.confidentiality" class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                        <option value="internal">Internal</option>
                        <option value="confidential">Confidential</option>
                        <option value="restricted">Restricted</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-widest text-on-surface-variant mb-1">File (PDF, DOCX, PNG, JPG · ≤ 25 MB)</label>
                    <input type="file" required accept=".pdf,.docx,.doc,.png,.jpg,.jpeg"
                           @change="(e) => form.file = e.target.files[0]"
                           class="w-full text-[12px]" />
                    <p v-if="form.errors.file" class="text-rose-600 text-xs mt-1">{{ form.errors.file }}</p>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-outline-variant/40">
                    <button type="button" @click="showUpload = false" class="rounded-lg border border-outline-variant px-4 py-2 text-[12px] font-bold">Cancel</button>
                    <button type="submit" :disabled="form.processing"
                            class="rounded-lg px-4 py-2 text-[12px] font-black text-white shadow-glow-sm"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        {{ form.processing ? 'Uploading…' : 'Upload' }}
                    </button>
                </div>
            </form>
        </SlidePanel>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Documents/Index.vue
git commit -m "feat(documents): add Documents Index page (tabs, search, upload panel)"
```

---

## Task 19: Vue — Documents Show page

**Files:**
- Create: `resources/js/Pages/Documents/Show.vue`

- [ ] **Step 1: Create the page**

```vue
<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Viewer from '@/Components/Documents/Viewer.vue';
import AnnotationLayer from '@/Components/Documents/AnnotationLayer.vue';
import SignaturePad from '@/Components/Documents/SignaturePad.vue';
import StampPicker from '@/Components/Documents/StampPicker.vue';
import RoutingSlipPanel from '@/Components/Documents/RoutingSlipPanel.vue';
import TimelineRail from '@/Components/Documents/TimelineRail.vue';

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);

const props = defineProps({
    document:     Object,
    activeModule: String,
});

const D = computed(() => props.document.data ?? props.document);

const docUrl = computed(() => route('documents.download', { document: D.value.uuid, version: D.value.current_version?.version_no }));
const downloadBurnedUrl = computed(() => route('documents.download', { document: D.value.uuid, burned: 1 }));

// Annotation state
const pageSize  = ref({ width: 0, height: 0 });
const currentPage = ref(1);
const pendingAnnotation = ref(null);  // { type, data } awaiting click placement
const showSigPad   = ref(false);
const showStamp    = ref(false);

// Routing modal
const showRouteModal = ref(false);
const routeForm = useForm({ recipients: [{ user_id: null, action_required: 'sign' }] });

function addRecipient() { routeForm.recipients.push({ user_id: null, action_required: 'sign' }); }
function removeRecipient(i) { routeForm.recipients.splice(i, 1); }

function submitRoute() {
    routeForm.post(route('documents.route', D.value.uuid), {
        onSuccess: () => { showRouteModal.value = false; routeForm.reset(); },
    });
}

// Place a pending annotation at coordinates emitted by AnnotationLayer
function placeAnnotation({ x_pct, y_pct, page }) {
    if (! pendingAnnotation.value) return;
    const dimensions = pendingAnnotation.value.type === 'stamp'
        ? { w_pct: 18, h_pct: 6 }
        : { w_pct: 22, h_pct: 8 };

    router.post(route('documents.annotations.store', D.value.uuid), {
        type:  pendingAnnotation.value.type,
        page,
        x_pct, y_pct,
        ...dimensions,
        data:  pendingAnnotation.value.data,
    }, { preserveScroll: true, onSuccess: () => { pendingAnnotation.value = null; } });
}

function onSigned({ png_base64 }) {
    pendingAnnotation.value = { type: 'signature', data: { png_base64 } };
    showSigPad.value = false;
}

function onStamp({ text, color }) {
    pendingAnnotation.value = { type: 'stamp', data: { text, color } };
    showStamp.value = false;
}

// Acting on a route
const actForm = useForm({ decision: '', comment: '' });
function act(routeId, decision) {
    actForm.decision = decision;
    if (decision === 'reject' && ! actForm.comment) {
        actForm.comment = prompt('Reason for rejection?') ?? '';
        if (! actForm.comment) return;
    }
    actForm.post(route('documents.routes.act', { document: D.value.uuid, route: routeId }), {
        preserveScroll: true,
        onSuccess: () => { actForm.reset(); },
    });
}

function withdraw() {
    if (! confirm('Withdraw this document from review?')) return;
    router.post(route('documents.withdraw', D.value.uuid));
}

function downloadBurned() {
    window.open(downloadBurnedUrl.value, '_blank');
}

// Determine if current user has an in-progress route
const myActiveRoute = computed(() =>
    D.value.routes?.find(r => r.status === 'in_progress' && r.to_user?.id === currentUserId.value)
);

// Action buttons
const canAnnotate = computed(() => D.value.status === 'draft' || !! myActiveRoute.value);
const canRoute    = computed(() => D.value.status === 'draft' && D.value.owner?.id === currentUserId.value);
const canWithdraw = computed(() => D.value.status === 'in_review' && D.value.owner?.id === currentUserId.value);
</script>

<template>
    <Head :title="D.title" />
    <AuthenticatedLayout :activeModule="activeModule">
        <template #header>
            <div class="space-y-2">
                <nav class="flex items-center gap-1.5 text-[12px] font-semibold text-on-surface-variant/60">
                    <Link :href="route('documents.index')" class="hover:text-secondary">Documents</Link>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <span class="text-on-surface">{{ D.ref_no }}</span>
                </nav>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="material-symbols-outlined text-[16px] text-secondary" style="font-variation-settings:'FILL' 1">description</span>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">{{ D.confidentiality?.toUpperCase() }} · {{ D.status_label?.toUpperCase() }}</p>
                        </div>
                        <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">{{ D.title }}</h1>
                        <p class="mt-1 text-[12px] text-on-surface-variant">{{ D.ref_no }} · owner {{ D.owner?.name }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a :href="docUrl" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px]">download</span> Original
                        </a>
                        <button @click="downloadBurned" class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black flex items-center gap-2">
                            <span class="material-symbols-outlined text-[16px]">picture_as_pdf</span> Burned PDF
                        </button>
                        <button v-if="canRoute" @click="showRouteModal = true"
                                class="btn-shimmer flex items-center gap-2 rounded-xl px-3 py-2 text-[12px] font-black text-white shadow-glow-sm"
                                style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                            <span class="material-symbols-outlined text-[17px]">send</span> Route
                        </button>
                        <button v-if="canWithdraw" @click="withdraw"
                                class="rounded-xl border border-rose-300 text-rose-700 px-3 py-2 text-[12px] font-black">
                            Withdraw
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <div class="grid lg:grid-cols-[1fr_320px] gap-6">
            <!-- Left: viewer + annotation layer -->
            <section class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card">
                <div v-if="canAnnotate" class="mb-3 flex flex-wrap items-center gap-2">
                    <button @click="showSigPad = true" class="rounded-lg border border-outline-variant px-3 py-1.5 text-[12px] font-black flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">gesture</span> Add signature
                    </button>
                    <button @click="showStamp = true" class="rounded-lg border border-outline-variant px-3 py-1.5 text-[12px] font-black flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-[15px]">verified</span> Add stamp
                    </button>
                    <span v-if="pendingAnnotation" class="text-[11px] font-bold text-amber-700">
                        Click on the page where you want to place the {{ pendingAnnotation.type }}.
                    </span>
                </div>
                <Viewer :src="docUrl" :mime="D.current_version?.mime"
                        @page-size="(s) => pageSize = s"
                        @page-changed="(p) => currentPage = p">
                    <template #overlay="{ pageSize: ps, page }">
                        <AnnotationLayer
                            :annotations="D.annotations"
                            :page="page"
                            :pageSize="ps"
                            :can-place="!!pendingAnnotation && canAnnotate"
                            :pending="pendingAnnotation"
                            @place="placeAnnotation" />
                    </template>
                </Viewer>
            </section>

            <!-- Right rail -->
            <aside class="space-y-4">
                <!-- Acting widget -->
                <div v-if="myActiveRoute" class="rounded-2xl border border-amber-300 bg-amber-50/50 p-4 shadow-card">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-amber-800 mb-1">Awaiting your action</p>
                    <p class="text-[12px] font-bold text-amber-900">{{ myActiveRoute.action_label }}</p>
                    <textarea v-model="actForm.comment" rows="2" placeholder="Comment (optional)"
                              class="mt-2 w-full rounded-lg border border-outline-variant px-2 py-1.5 text-[12px]"></textarea>
                    <div class="mt-2 flex items-center gap-2">
                        <button @click="act(myActiveRoute.id, 'complete')" class="flex-1 rounded-lg px-3 py-2 text-[12px] font-black text-white"
                                style="background:linear-gradient(135deg,#059669,#10b981)">Sign &amp; forward</button>
                        <button @click="act(myActiveRoute.id, 'reject')" class="rounded-lg border border-rose-300 text-rose-700 px-3 py-2 text-[12px] font-black">Reject</button>
                    </div>
                </div>

                <RoutingSlipPanel :routes="D.routes ?? []" />
                <TimelineRail :events="D.events ?? []" />
            </aside>
        </div>

        <!-- Modals -->
        <SignaturePad v-if="showSigPad" @signed="onSigned" @cancel="showSigPad = false" />
        <StampPicker v-if="showStamp" @stamp="onStamp" @cancel="showStamp = false" />

        <!-- Route modal -->
        <div v-if="showRouteModal" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
            <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-lg">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Send to recipients in order</p>
                <h2 class="text-lg font-black text-primary mb-3">Route document</h2>
                <div class="space-y-2">
                    <div v-for="(r, i) in routeForm.recipients" :key="i" class="flex items-center gap-2">
                        <span class="w-7 text-center font-mono text-[12px] font-black">{{ i + 1 }}</span>
                        <input v-model.number="r.user_id" type="number" placeholder="Staff user ID"
                               class="flex-1 rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
                        <select v-model="r.action_required" class="rounded-lg border border-outline-variant px-2 py-2 text-[12px]">
                            <option value="sign">Sign</option>
                            <option value="review">Review</option>
                            <option value="approve">Approve</option>
                            <option value="acknowledge">Acknowledge</option>
                        </select>
                        <button v-if="routeForm.recipients.length > 1" @click="removeRecipient(i)" class="text-rose-600 text-[14px]">✕</button>
                    </div>
                </div>
                <button @click="addRecipient" class="mt-2 text-[12px] font-black text-secondary">+ Add recipient</button>
                <div class="mt-4 flex justify-end gap-2">
                    <button @click="showRouteModal = false" class="rounded-lg border border-outline-variant px-4 py-2 text-[12px] font-bold">Cancel</button>
                    <button @click="submitRoute" :disabled="routeForm.processing"
                            class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                            style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                        {{ routeForm.processing ? 'Routing…' : 'Send' }}
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

Note: the recipient picker uses raw user IDs for v1 to keep scope tight; a future task can swap in a typeahead. Make sure your sidebar's `$page` accessor for `auth.user.id` matches the existing pattern in CIHRMS Vue pages — if it differs, copy that exact pattern.

- [ ] **Step 2: Build to verify**

```bash
npm run build
```
Expected: build passes.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Documents/Show.vue
git commit -m "feat(documents): add Documents Show page (viewer, annotations, routing, actions)"
```

---

## Task 20: Feature tests

**Files:**
- Create: `tests/Feature/Documents/UploadDocumentTest.php`
- Create: `tests/Feature/Documents/RouteDocumentTest.php`
- Create: `tests/Feature/Documents/AnnotateDocumentTest.php`
- Create: `tests/Feature/Documents/ActOnRouteTest.php`
- Create: `tests/Feature/Documents/DownloadDocumentTest.php`
- Create: `tests/Feature/Documents/WithdrawDocumentTest.php`

- [ ] **Step 1: `UploadDocumentTest`**

```php
<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('uploads a document', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('documents.create');

    $file = UploadedFile::fake()->create('memo.pdf', 200, 'application/pdf');

    $this->actingAs($user)
        ->post(route('documents.store'), [
            'title' => 'Annual Memo',
            'description' => 'Year-end memo',
            'file' => $file,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('documents', [
        'title'    => 'Annual Memo',
        'owner_id' => $user->id,
        'status'   => 'draft',
    ]);
    $this->assertDatabaseCount('document_versions', 1);
    $this->assertDatabaseHas('document_events', ['type' => 'uploaded']);
});

it('rejects oversized uploads', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('documents.create');
    $file = UploadedFile::fake()->create('big.pdf', 26000, 'application/pdf');  // > 25 MB

    $this->actingAs($user)
        ->post(route('documents.store'), ['title' => 'big', 'file' => $file])
        ->assertSessionHasErrors('file');
});
```

- [ ] **Step 2: `RouteDocumentTest`**

```php
<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('owner routes a draft to two recipients in order', function () {
    [$owner, $a, $b] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);
    $owner->givePermissionTo('documents.create');

    $this->actingAs($owner)
        ->post(route('documents.route', $doc->uuid), [
            'recipients' => [
                ['user_id' => $a->id, 'action_required' => 'sign'],
                ['user_id' => $b->id, 'action_required' => 'approve'],
            ],
        ])
        ->assertRedirect();

    expect($doc->fresh()->status)->toBe(DocumentStatus::InReview);
    $this->assertDatabaseCount('document_routes', 2);
    $this->assertDatabaseHas('document_routes', ['document_id' => $doc->id, 'sequence' => 1, 'to_user_id' => $a->id, 'status' => 'in_progress']);
    $this->assertDatabaseHas('document_routes', ['document_id' => $doc->id, 'sequence' => 2, 'to_user_id' => $b->id, 'status' => 'pending']);
});

it('forbids routing if not owner', function () {
    [$owner, $other, $r] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $this->actingAs($other)
        ->post(route('documents.route', $doc->uuid), [
            'recipients' => [['user_id' => $r->id, 'action_required' => 'sign']],
        ])
        ->assertForbidden();
});
```

- [ ] **Step 3: `AnnotateDocumentTest`**

```php
<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('owner can annotate a draft', function () {
    $owner = User::factory()->create();
    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $this->actingAs($owner)
        ->post(route('documents.annotations.store', $doc->uuid), [
            'type'  => 'stamp',
            'page'  => 1,
            'x_pct' => 10,
            'y_pct' => 20,
            'w_pct' => 18,
            'h_pct' => 6,
            'data'  => ['text' => 'APPROVED', 'color' => '#059669'],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('document_annotations', [
        'document_id' => $doc->id,
        'type'        => 'stamp',
        'page'        => 1,
    ]);
});
```

- [ ] **Step 4: `ActOnRouteTest`**

```php
<?php

use App\Enums\DocumentRouteAction;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentRoutingService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('recipient can complete their route hop', function () {
    [$owner, $a] = [User::factory()->create(), User::factory()->create()];
    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    app(DocumentRoutingService::class)->route($doc, [
        ['user_id' => $a->id, 'action_required' => DocumentRouteAction::Sign],
    ]);
    $route = $doc->routes()->first();

    $this->actingAs($a)
        ->post(route('documents.routes.act', ['document' => $doc->uuid, 'route' => $route->id]), [
            'decision' => 'complete',
        ])
        ->assertRedirect();

    expect($doc->fresh()->status->value)->toBe('completed');
});

it('non-recipient cannot act', function () {
    [$owner, $a, $imposter] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    app(DocumentRoutingService::class)->route($doc, [
        ['user_id' => $a->id, 'action_required' => DocumentRouteAction::Sign],
    ]);
    $route = $doc->routes()->first();

    $this->actingAs($imposter)
        ->post(route('documents.routes.act', ['document' => $doc->uuid, 'route' => $route->id]), ['decision' => 'complete'])
        ->assertForbidden();
});
```

- [ ] **Step 5: `DownloadDocumentTest`**

```php
<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('downloads original file', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $doc = Document::factory()->for($owner, 'owner')->create();

    $file = UploadedFile::fake()->create('memo.pdf', 50, 'application/pdf');
    Storage::disk('local')->putFileAs(sprintf('documents/%s/v1', $doc->uuid), $file, 'memo.pdf');

    $version = DocumentVersion::factory()->for($doc)->create([
        'original_name' => 'memo.pdf',
        'storage_path'  => sprintf('documents/%s/v1/memo.pdf', $doc->uuid),
        'mime'          => 'application/pdf',
    ]);
    $doc->update(['current_version_id' => $version->id]);

    $this->actingAs($owner)
        ->get(route('documents.download', $doc->uuid))
        ->assertOk();
});
```

- [ ] **Step 6: `WithdrawDocumentTest`**

```php
<?php

use App\Enums\DocumentRouteAction;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\DocumentRoutingService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('owner withdraws an in-review document', function () {
    [$owner, $a] = [User::factory()->create(), User::factory()->create()];
    $doc = Document::factory()->for($owner, 'owner')->create();
    $v   = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    app(DocumentRoutingService::class)->route($doc, [
        ['user_id' => $a->id, 'action_required' => DocumentRouteAction::Sign],
    ]);

    $this->actingAs($owner)
        ->post(route('documents.withdraw', $doc->uuid))
        ->assertRedirect();

    expect($doc->fresh()->status)->toBe(DocumentStatus::Withdrawn);
});
```

- [ ] **Step 7: Run the suite**

```bash
php artisan test --filter=Documents
```
Expected: all feature tests pass.

- [ ] **Step 8: Commit**

```bash
git add tests/Feature/Documents
git commit -m "test(documents): feature tests for upload/route/annotate/act/download/withdraw"
```

---

## Task 21: Final verification

- [ ] **Step 1: Run full test suite**

```bash
php artisan test --parallel
```
Expected: all tests pass.

- [ ] **Step 2: Build frontend**

```bash
npm run build
```
Expected: build passes.

- [ ] **Step 3: Smoke test in browser**

1. `php artisan serve` (if not already running)
2. Log in
3. Sidebar should show **Documents** entry
4. Click → Documents/Index loads with empty list and "Upload Document" button
5. Upload a small PDF — redirects to Show page
6. Click "Add signature" → draw → place on page → annotation appears
7. Click "Add stamp" → pick "APPROVED" → place — appears
8. Click "Route" → enter another user's ID with action "sign" → submit
9. Log in as that user → Documents → Inbox shows the doc with badge
10. Open it → see prior signature + stamp → click "Sign & forward" → status becomes Completed (single-hop test)
11. Download "Burned PDF" — should produce a PDF with both annotations

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat(documents): vertical slice complete (upload, route, sign/stamp, burn, audit)"
```

---

## Self-review notes (from plan author)

**Coverage check vs spec:**
- §2 In-Scope items 1–7 → all covered (1: Index/upload, 2: SignaturePad/StampPicker/AnnotationLayer, 3: routing slip Tasks 7+, 4: Inbox tab in Index, 5: TimelineRail + events, 6: download burned/original, 7: addVersion route)
- §5 Schema (5 tables) → Task 3
- §6 Routes (12 endpoints) → Task 13
- §7 Frontend (8 components/pages) → Tasks 14–19
- §8 Services (4) → Tasks 6–9
- §10 Audit integration → already wired via existing `audit` middleware; module also writes `document_events`
- §11 Dependencies → Task 1
- §14 Testing strategy → Tasks 7 (unit) + 20 (feature)

**Known gaps / explicit deferrals (matching spec §3):**
- Recipient picker uses raw user IDs in v1 (typeahead deferred)
- DOCX preview shows "download to view" message
- DOCX→PDF returns 501 via `ConversionNotSupportedException`
- No parallel routing UI (schema supports `parallel_routing` for v2)

**Total estimated tasks:** 21
**Total estimated time (skilled implementer):** 4–6 hours for backend tracks + 3–4 hours frontend + 1–2 hours testing & QA = ~1 working day end-to-end.
