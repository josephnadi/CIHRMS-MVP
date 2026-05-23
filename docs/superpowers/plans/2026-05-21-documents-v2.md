# Documents v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add manipulable annotations and user-managed stamp / letterhead / watermark asset libraries to the Documents module.

**Architecture:** Additive. New tables `stamp_assets`, `letterhead_templates`, `watermark_templates`. Additive `documents` columns `letterhead_id`, `watermark_id`, `watermark_mode`. Assets use a three-scope ownership model (`personal | department | organization`). Render & composer services are extended to honor per-document overrides while preserving existing defaults (`public/img/letterhead.png` and the restricted auto-watermark).

**Tech Stack:** Laravel 13, Eloquent, Policies, FormRequests, Inertia v2, Vue 3 `<script setup>`, Tailwind v3, Pest, TCPDF + FPDI.

**Spec:** [../specs/2026-05-21-documents-v2-design.md](../specs/2026-05-21-documents-v2-design.md)

---

## Status of Phase 1 (already shipped — do not re-implement)

Edit / Delete / Share landed prior to this plan. Verified artifacts:

- Migration: `database/migrations/2026_06_09_000001_create_document_shares.php`
- Model: `app/Models/DocumentShare.php` + `Document::shares()` relation
- Enum cases: `DocumentEventType::{Updated, Deleted, Shared, Unshared}`, `App\Enums\DocumentShareAudience`
- Service: `app/Services/DocumentShareService.php`; `DocumentService::{updateMetadata, softDelete}`
- Policy: `DocumentPolicy::{share, view (widened), update, delete}`
- Controllers: `DocumentController::{update, destroy}`; `DocumentShareController::{store, destroy}`
- Requests: `UpdateDocumentRequest`, `ShareDocumentRequest`
- Routes: `PATCH /documents/{document}`, `DELETE /documents/{document}`, share routes
- Seeder: `documents.share_organization` permission added to `DocumentPermissionsSeeder`
- Frontend: Edit drawer, Share modal, Delete button, shares list all live in `resources/js/Pages/Documents/Show.vue`
- Tests: `UpdateDocumentTest`, `DeleteDocumentTest`, `ShareDocumentTest`

Phase 4 and Phase 5 add `letterhead_id`, `watermark_id`, `watermark_mode` columns to `documents` and extend the existing Edit drawer with selectors.

---

## Conventions

- Migrations are timestamped `YYYY_MM_DD_HHMMSS_<name>.php` — use the next free slot. This plan uses `2026_06_10_xxxxxx` placeholders.
- Tests use Pest. Permissions on test users use the per-user JSON column: `User::factory()->create(['permissions' => ['…']])`.
- Test storage: `Storage::fake('local')` in `beforeEach`.
- New `*Policy` classes require explicit registration in `app/Providers/AuthServiceProvider.php`.
- Frontend pages use `AuthenticatedLayout` with `#page-header-mount` teleport and Inertia's `useForm()`.
- Settings sidebar nav lives in `resources/js/Layouts/AuthenticatedLayout.vue`. Add new entries under the Settings group.
- New permissions go into `DocumentPermissionsSeeder` (do not create per-feature seeders).
- All work this plan describes is for files under `cihrms-mvp/`. Run all commands from that directory.

---

## File Structure (Phases 2–5)

```
PHASE 2 — Manipulable annotations
  app/Enums/DocumentEventType.php                            (modify)
  app/Http/Controllers/DocumentController.php                (modify — updateAnnotation)
  app/Http/Requests/Documents/MoveAnnotationRequest.php      (new)
  app/Policies/DocumentPolicy.php                            (modify — moveAnnotation)
  app/Services/DocumentService.php                           (modify — moveAnnotation)
  app/Models/DocumentAnnotation.php                          (modify — route relation)
  app/Http/Resources/DocumentAnnotationResource.php          (modify — user, route_status)
  database/factories/DocumentAnnotationFactory.php           (verify/create)
  routes/web.php                                             (modify — PATCH annotation route)
  resources/js/Components/Documents/AnnotationLayer.vue      (rewrite)
  resources/js/Components/Documents/DraggableAnnotation.vue  (new)
  resources/js/Pages/Documents/Show.vue                      (modify — pass props)
  tests/Feature/Documents/MoveAnnotationTest.php             (new)
  tests/Unit/Policies/DocumentPolicyMoveAnnotationTest.php   (new)

PHASE 3 — Stamp asset library
  app/Enums/AssetOwnerScope.php                              (new)
  app/Models/StampAsset.php                                  (new)
  app/Policies/StampAssetPolicy.php                          (new)
  app/Services/StampAssetService.php                         (new)
  app/Http/Controllers/Settings/StampAssetController.php     (new)
  app/Http/Requests/DocumentAssets/StoreStampAssetRequest.php(new)
  app/Http/Resources/StampAssetResource.php                  (new)
  app/Providers/AuthServiceProvider.php                      (modify — register policy)
  database/migrations/<ts>_create_stamp_assets_table.php     (new)
  database/factories/StampAssetFactory.php                   (new)
  database/seeders/DocumentPermissionsSeeder.php             (modify — document_assets.manage)
  routes/web.php                                             (modify — /settings/stamps)
  resources/js/Pages/Settings/Stamps.vue                     (new)
  resources/js/Components/Documents/StampPicker.vue          (modify — tabs)
  resources/js/Layouts/AuthenticatedLayout.vue               (modify — nav link)
  tests/Feature/DocumentAssets/StampAssetTest.php            (new)

PHASE 4 — Letterhead templates
  app/Models/LetterheadTemplate.php                          (new)
  app/Policies/LetterheadTemplatePolicy.php                  (new)
  app/Services/LetterheadTemplateService.php                 (new)
  app/Services/DocumentComposerService.php                   (modify — render template image)
  app/Http/Controllers/Settings/LetterheadTemplateController.php (new)
  app/Http/Requests/DocumentAssets/StoreLetterheadRequest.php(new)
  app/Http/Requests/Documents/ComposeDocumentRequest.php     (modify — letterhead_id)
  app/Http/Requests/Documents/UpdateDocumentRequest.php      (modify — accept letterhead_id)
  app/Http/Resources/LetterheadTemplateResource.php          (new)
  app/Http/Resources/DocumentResource.php                    (modify — expose letterhead_id)
  app/Models/Document.php                                    (modify — fillable + relation)
  app/Providers/AuthServiceProvider.php                      (modify)
  database/migrations/<ts>_create_letterhead_templates_table.php (new)
  database/migrations/<ts>_add_letterhead_id_to_documents_table.php (new)
  database/factories/LetterheadTemplateFactory.php           (new)
  database/seeders/DefaultDocumentAssetsSeeder.php           (new — seeds default org letterhead)
  database/seeders/DatabaseSeeder.php                        (modify — call new seeder)
  routes/web.php                                             (modify — /settings/letterheads)
  resources/js/Pages/Settings/Letterheads.vue                (new)
  resources/js/Pages/Documents/Compose.vue                   (modify — dropdown)
  resources/js/Pages/Documents/Show.vue                      (modify — letterhead picker in Edit drawer)
  resources/js/Layouts/AuthenticatedLayout.vue               (modify — nav link)
  tests/Feature/DocumentAssets/LetterheadTemplateTest.php    (new)
  tests/Feature/Documents/ComposeWithLetterheadTest.php      (new)

PHASE 5 — Watermark templates
  app/Enums/DocumentWatermarkMode.php                        (new)
  app/Models/WatermarkTemplate.php                           (new)
  app/Policies/WatermarkTemplatePolicy.php                   (new)
  app/Services/WatermarkTemplateService.php                  (new)
  app/Services/DocumentRenderService.php                     (modify — template picker)
  app/Http/Controllers/Settings/WatermarkTemplateController.php (new)
  app/Http/Controllers/DocumentController.php                (modify — `always` mode for non-burned download)
  app/Http/Requests/DocumentAssets/StoreWatermarkRequest.php (new)
  app/Http/Requests/Documents/UpdateDocumentRequest.php      (modify — accept watermark_id/mode)
  app/Http/Resources/WatermarkTemplateResource.php           (new)
  app/Http/Resources/DocumentResource.php                    (modify — expose watermark_id, watermark_mode)
  app/Models/Document.php                                    (modify — fillable + relation)
  app/Providers/AuthServiceProvider.php                      (modify)
  database/migrations/<ts>_create_watermark_templates_table.php (new)
  database/migrations/<ts>_add_watermark_to_documents_table.php (new)
  database/factories/WatermarkTemplateFactory.php            (new)
  database/seeders/DefaultDocumentAssetsSeeder.php           (modify — default restricted watermark)
  routes/web.php                                             (modify — /settings/watermarks)
  resources/js/Pages/Settings/Watermarks.vue                 (new)
  resources/js/Pages/Documents/Show.vue                      (modify — watermark/mode pickers)
  resources/js/Layouts/AuthenticatedLayout.vue               (modify — nav link)
  tests/Feature/DocumentAssets/WatermarkTemplateTest.php     (new)
  tests/Feature/Documents/WatermarkOverrideTest.php          (new)
```

---

## Phase 2 — Manipulable Annotations

**Goal:** Let the annotation owner (and the document owner while Draft) drag, resize, rotate, and delete placed annotations. Lock annotations bound to a Completed route for signature integrity.

### Task 2.1: Extend `DocumentEventType` enum

**Files:** Modify `app/Enums/DocumentEventType.php`

- [ ] **Step 1:** Add two cases after the Phase 1 additions block:

```php
    // Documents v2 — Phase 2 additions.
    case AnnotationMoved   = 'annotation_moved';
    case AnnotationResized = 'annotation_resized';
```

- [ ] **Step 2:** Run `php artisan test --filter=Documents` — confirm no regressions.
- [ ] **Step 3:** Commit `feat(documents): add AnnotationMoved/Resized event types`.

---

### Task 2.2: Add `route()` relation on `DocumentAnnotation`

**Files:** Modify `app/Models/DocumentAnnotation.php`

- [ ] **Step 1:** Add the relation if missing:

```php
    public function route(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\DocumentRoute::class, 'route_id');
    }
```

- [ ] **Step 2:** Commit `feat(documents): add route relation on DocumentAnnotation`.

---

### Task 2.3: `DocumentPolicy::moveAnnotation` (TDD)

**Files:**
- Modify: `app/Policies/DocumentPolicy.php`
- Create: `tests/Unit/Policies/DocumentPolicyMoveAnnotationTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Enums\DocumentRouteStatus;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentRoute;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Policies\DocumentPolicy;

it('owner can move their own annotation on a draft', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create(['status' => DocumentStatus::Draft]);
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);
    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
    ]);
    expect((new DocumentPolicy())->moveAnnotation($owner, $ann))->toBeTrue();
});

it('third party cannot move annotation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create(['status' => DocumentStatus::Draft]);
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);
    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
    ]);
    expect((new DocumentPolicy())->moveAnnotation($other, $ann))->toBeFalse();
});

it('locks annotation on completed route', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create(['status' => DocumentStatus::InReview]);
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);
    $r = DocumentRoute::factory()->for($doc)->for($v, 'version')->create([
        'from_user_id' => $owner->id, 'to_user_id' => $owner->id,
        'status' => DocumentRouteStatus::Completed,
    ]);
    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'route_id' => $r->id,
    ]);
    expect((new DocumentPolicy())->moveAnnotation($owner, $ann))->toBeFalse();
});
```

- [ ] **Step 2:** Run `php artisan test tests/Unit/Policies/DocumentPolicyMoveAnnotationTest.php` — expect FAIL referencing `moveAnnotation` undefined.

- [ ] **Step 3: Implement the policy method** at the bottom of `DocumentPolicy`:

```php
    public function moveAnnotation(User $user, \App\Models\DocumentAnnotation $annotation): bool
    {
        $doc        = $annotation->document;
        $isCreator  = $annotation->user_id === $user->id;
        $isDocOwner = $doc?->owner_id === $user->id;
        $isDocDraft = $doc?->status === DocumentStatus::Draft;

        if (! ($isCreator || ($isDocOwner && $isDocDraft))) {
            return false;
        }
        if ($annotation->route_id) {
            $route = $annotation->route;
            if ($route && $route->status === DocumentRouteStatus::Completed) {
                return false;
            }
        }
        return true;
    }
```

- [ ] **Step 4:** Run the test again — expect PASS (3 cases).
- [ ] **Step 5:** Commit `feat(documents): moveAnnotation policy gate`.

---

### Task 2.4: `MoveAnnotationRequest`

**Files:** Create `app/Http/Requests/Documents/MoveAnnotationRequest.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class MoveAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moveAnnotation', $this->route('annotation')) === true;
    }

    public function rules(): array
    {
        return [
            'x_pct'    => ['sometimes', 'numeric', 'between:0,100'],
            'y_pct'    => ['sometimes', 'numeric', 'between:0,100'],
            'w_pct'    => ['sometimes', 'numeric', 'between:4,80'],
            'h_pct'    => ['sometimes', 'numeric', 'between:4,80'],
            'rotation' => ['sometimes', 'integer', 'between:-180,180'],
        ];
    }
}
```

- [ ] **Step 2:** Commit `feat(documents): MoveAnnotationRequest`.

---

### Task 2.5: Service + controller + route + factory + feature test

**Files:**
- Modify: `app/Services/DocumentService.php`
- Modify: `app/Http/Controllers/DocumentController.php`
- Modify: `routes/web.php`
- Verify/Create: `database/factories/DocumentAnnotationFactory.php`
- Create: `tests/Feature/Documents/MoveAnnotationTest.php`

- [ ] **Step 1: Add service method** after `removeAnnotation()` in `DocumentService`:

```php
    public function moveAnnotation(DocumentAnnotation $annotation, array $attrs, User $by): DocumentAnnotation
    {
        $fields = ['x_pct', 'y_pct', 'w_pct', 'h_pct', 'rotation'];
        $clean  = array_intersect_key($attrs, array_flip($fields));

        $before = $annotation->only($fields);
        $annotation->update($clean);
        $after  = $annotation->fresh()->only($fields);

        $posChanged = $before['x_pct'] != $after['x_pct'] || $before['y_pct'] != $after['y_pct'];
        $dimChanged = $before['w_pct'] != $after['w_pct'] || $before['h_pct'] != $after['h_pct'] || $before['rotation'] != $after['rotation'];

        if ($posChanged) {
            $this->logEvent($annotation->document, $by, DocumentEventType::AnnotationMoved, [
                'annotation_id' => $annotation->id,
                'from' => ['x' => $before['x_pct'], 'y' => $before['y_pct']],
                'to'   => ['x' => $after['x_pct'],  'y' => $after['y_pct']],
            ]);
        }
        if ($dimChanged) {
            $this->logEvent($annotation->document, $by, DocumentEventType::AnnotationResized, [
                'annotation_id' => $annotation->id,
                'from' => ['w' => $before['w_pct'], 'h' => $before['h_pct'], 'rot' => $before['rotation']],
                'to'   => ['w' => $after['w_pct'],  'h' => $after['h_pct'],  'rot' => $after['rotation']],
            ]);
        }
        return $annotation->fresh();
    }
```

- [ ] **Step 2: Add controller method** after `removeAnnotation` in `DocumentController`:

```php
    public function updateAnnotation(
        \App\Http\Requests\Documents\MoveAnnotationRequest $request,
        Document $document,
        \App\Models\DocumentAnnotation $annotation,
    ) {
        abort_unless($annotation->document_id === $document->id, 404);
        $updated = $this->docs->moveAnnotation($annotation, $request->validated(), $request->user());
        return back()->with([
            'flash.success' => 'Annotation updated.',
            'annotation'    => (new \App\Http\Resources\DocumentAnnotationResource($updated))->resolve(),
        ]);
    }
```

- [ ] **Step 3: Add route** in the `documents.` group of `routes/web.php`, right after the existing `annotations.destroy` line:

```php
        Route::patch('/{document}/annotations/{annotation}', [DocumentController::class, 'updateAnnotation'])->name('annotations.update');
```

- [ ] **Step 4: Ensure factory exists.** If `database/factories/DocumentAnnotationFactory.php` does not exist, create:

```php
<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentAnnotationFactory extends Factory
{
    protected $model = DocumentAnnotation::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version_id'  => DocumentVersion::factory(),
            'user_id'     => User::factory(),
            'type'        => 'signature',
            'page'        => 1,
            'x_pct'       => 10, 'y_pct' => 10, 'w_pct' => 22, 'h_pct' => 8,
            'rotation'    => 0,
            'data'        => ['png_base64' => 'data:image/png;base64,iVBORw0KGgo='],
        ];
    }
}
```

- [ ] **Step 5: Write feature test** at `tests/Feature/Documents/MoveAnnotationTest.php`:

```php
<?php

use App\Models\Document;
use App\Models\DocumentAnnotation;
use App\Models\DocumentVersion;
use App\Models\User;

it('owner can move their own annotation', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create();
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
        'x_pct' => 10, 'y_pct' => 10, 'w_pct' => 22, 'h_pct' => 8,
    ]);

    $this->actingAs($owner)
        ->patch(route('documents.annotations.update', ['document' => $doc->uuid, 'annotation' => $ann->id]), [
            'x_pct' => 30, 'y_pct' => 40, 'w_pct' => 25, 'h_pct' => 10, 'rotation' => 15,
        ])
        ->assertRedirect();

    $fresh = $ann->fresh();
    expect((float) $fresh->x_pct)->toBe(30.0)
        ->and((float) $fresh->y_pct)->toBe(40.0)
        ->and($fresh->rotation)->toBe(15);

    $this->assertDatabaseHas('document_events', ['type' => 'annotation_moved']);
    $this->assertDatabaseHas('document_events', ['type' => 'annotation_resized']);
});

it('non-owner cannot move annotation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create();
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
    ]);

    $this->actingAs($other)
        ->patch(route('documents.annotations.update', ['document' => $doc->uuid, 'annotation' => $ann->id]), [
            'x_pct' => 50,
        ])
        ->assertForbidden();
});

it('rejects out-of-range coordinates', function () {
    $owner = User::factory()->create();
    $doc   = Document::factory()->for($owner, 'owner')->create();
    $v     = DocumentVersion::factory()->for($doc)->create();
    $doc->update(['current_version_id' => $v->id]);

    $ann = DocumentAnnotation::factory()->for($doc)->for($v, 'version')->create([
        'user_id' => $owner->id, 'type' => 'signature',
    ]);

    $this->actingAs($owner)
        ->patch(route('documents.annotations.update', ['document' => $doc->uuid, 'annotation' => $ann->id]), [
            'x_pct' => 150,
        ])
        ->assertSessionHasErrors('x_pct');
});
```

- [ ] **Step 6:** Run `php artisan test tests/Feature/Documents/MoveAnnotationTest.php` — expect PASS (3).
- [ ] **Step 7:** Commit `feat(documents): PATCH annotation endpoint for drag/resize/rotate`.

---

### Task 2.6: `DraggableAnnotation.vue`

**Files:** Create `resources/js/Components/Documents/DraggableAnnotation.vue`

- [ ] **Step 1: Create the file** with the full body:

```vue
<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    annotation:    { type: Object, required: true },
    canManipulate: { type: Boolean, default: false },
});
const emit = defineEmits(['update', 'delete']);

const draft = ref({
    x_pct: Number(props.annotation.x_pct),
    y_pct: Number(props.annotation.y_pct),
    w_pct: Number(props.annotation.w_pct),
    h_pct: Number(props.annotation.h_pct),
    rotation: Number(props.annotation.rotation ?? 0),
});
const dragging = ref(null);

function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

function start(mode, handle, e) {
    if (! props.canManipulate) return;
    e.stopPropagation(); e.preventDefault();
    const parent = e.currentTarget.closest('[data-annotation-layer]');
    dragging.value = {
        mode, handle,
        startX: e.clientX, startY: e.clientY,
        start: { ...draft.value },
        parentRect: parent.getBoundingClientRect(),
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onEnd);
}

function onMove(e) {
    const d = dragging.value;
    if (! d) return;
    const dxPct = ((e.clientX - d.startX) / d.parentRect.width) * 100;
    const dyPct = ((e.clientY - d.startY) / d.parentRect.height) * 100;

    if (d.mode === 'move') {
        draft.value.x_pct = clamp(d.start.x_pct + dxPct, 0, 100 - draft.value.w_pct);
        draft.value.y_pct = clamp(d.start.y_pct + dyPct, 0, 100 - draft.value.h_pct);
    } else if (d.mode === 'resize') {
        const minW = 4, minH = 4, maxW = 80, maxH = 80;
        if (d.handle === 'se') {
            draft.value.w_pct = clamp(d.start.w_pct + dxPct, minW, maxW);
            draft.value.h_pct = clamp(d.start.h_pct + dyPct, minH, maxH);
        } else if (d.handle === 'sw') {
            const nw = clamp(d.start.w_pct - dxPct, minW, maxW);
            draft.value.x_pct = clamp(d.start.x_pct + (d.start.w_pct - nw), 0, 100);
            draft.value.w_pct = nw;
            draft.value.h_pct = clamp(d.start.h_pct + dyPct, minH, maxH);
        } else if (d.handle === 'ne') {
            draft.value.w_pct = clamp(d.start.w_pct + dxPct, minW, maxW);
            const nh = clamp(d.start.h_pct - dyPct, minH, maxH);
            draft.value.y_pct = clamp(d.start.y_pct + (d.start.h_pct - nh), 0, 100);
            draft.value.h_pct = nh;
        } else if (d.handle === 'nw') {
            const nw = clamp(d.start.w_pct - dxPct, minW, maxW);
            const nh = clamp(d.start.h_pct - dyPct, minH, maxH);
            draft.value.x_pct = clamp(d.start.x_pct + (d.start.w_pct - nw), 0, 100);
            draft.value.y_pct = clamp(d.start.y_pct + (d.start.h_pct - nh), 0, 100);
            draft.value.w_pct = nw;
            draft.value.h_pct = nh;
        }
    } else if (d.mode === 'rotate') {
        const boxLeft = d.parentRect.left + (draft.value.x_pct / 100) * d.parentRect.width;
        const boxTop  = d.parentRect.top  + (draft.value.y_pct / 100) * d.parentRect.height;
        const cx = boxLeft + (draft.value.w_pct / 100) * d.parentRect.width  / 2;
        const cy = boxTop  + (draft.value.h_pct / 100) * d.parentRect.height / 2;
        const deg = Math.atan2(e.clientY - cy, e.clientX - cx) * 180 / Math.PI + 90;
        draft.value.rotation = Math.round(((deg + 180) % 360) - 180);
    }
}

function onEnd() {
    if (! dragging.value) return;
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onEnd);
    dragging.value = null;
    emit('update', { ...draft.value });
}

const boxStyle = computed(() => ({
    position: 'absolute',
    left:   draft.value.x_pct + '%',
    top:    draft.value.y_pct + '%',
    width:  draft.value.w_pct + '%',
    height: draft.value.h_pct + '%',
    transform: `rotate(${draft.value.rotation}deg)`,
    transformOrigin: 'center center',
}));

const a = computed(() => props.annotation);
</script>

<template>
    <div :style="boxStyle"
         :class="['group select-none', canManipulate ? 'cursor-move' : 'pointer-events-none']"
         @pointerdown="start('move', null, $event)">
        <img v-if="a.type === 'signature' || a.type === 'initial' || (a.type === 'stamp' && a.data?.png_base64)"
             :src="a.data.png_base64" draggable="false"
             class="w-full h-full object-contain pointer-events-none" />
        <div v-else-if="a.type === 'stamp'"
             class="flex items-center justify-center w-full h-full border-2 font-black text-center pointer-events-none"
             :style="{ color: a.data?.color ?? '#cc0000', borderColor: a.data?.color ?? '#cc0000' }">
            {{ a.data?.text ?? 'STAMP' }}
        </div>
        <div v-else-if="a.type === 'text'"
             class="text-[11px] font-semibold text-on-surface pointer-events-none">
            {{ a.data?.text }}
        </div>
        <template v-if="canManipulate">
            <button type="button" @pointerdown.stop="emit('delete')"
                    class="absolute -top-3 -right-3 w-6 h-6 rounded-full bg-rose-600 text-white text-[12px] font-black shadow opacity-0 group-hover:opacity-100 transition-opacity">✕</button>
            <div @pointerdown.stop="start('rotate', null, $event)"
                 class="absolute left-1/2 -top-6 w-3 h-3 -ml-1.5 rounded-full bg-secondary border-2 border-white shadow cursor-grab opacity-0 group-hover:opacity-100" title="Rotate"></div>
            <div @pointerdown.stop="start('resize', 'nw', $event)" class="absolute -top-1 -left-1 w-3 h-3 rounded-sm bg-secondary border border-white cursor-nwse-resize opacity-0 group-hover:opacity-100"></div>
            <div @pointerdown.stop="start('resize', 'ne', $event)" class="absolute -top-1 -right-1 w-3 h-3 rounded-sm bg-secondary border border-white cursor-nesw-resize opacity-0 group-hover:opacity-100"></div>
            <div @pointerdown.stop="start('resize', 'sw', $event)" class="absolute -bottom-1 -left-1 w-3 h-3 rounded-sm bg-secondary border border-white cursor-nesw-resize opacity-0 group-hover:opacity-100"></div>
            <div @pointerdown.stop="start('resize', 'se', $event)" class="absolute -bottom-1 -right-1 w-3 h-3 rounded-sm bg-secondary border border-white cursor-nwse-resize opacity-0 group-hover:opacity-100"></div>
        </template>
    </div>
</template>
```

- [ ] **Step 2:** Commit `feat(documents): DraggableAnnotation component`.

---

### Task 2.7: Wire `AnnotationLayer.vue` + `Show.vue` + resource

**Files:**
- Modify: `resources/js/Components/Documents/AnnotationLayer.vue` (full rewrite)
- Modify: `resources/js/Pages/Documents/Show.vue` (extend `<AnnotationLayer>` props)
- Modify: `app/Http/Controllers/DocumentController.php` (eager-load `annotations.route`)
- Modify: `app/Http/Resources/DocumentAnnotationResource.php` (expose `user`, `route_status`)

- [ ] **Step 1: Replace `AnnotationLayer.vue`** with:

```vue
<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import DraggableAnnotation from './DraggableAnnotation.vue';

const props = defineProps({
    annotations: { type: Array, default: () => [] },
    page:        { type: Number, default: 1 },
    pageSize:    { type: Object, required: true },
    canPlace:    { type: Boolean, default: false },
    pending:     { type: Object, default: null },
    docUuid:     { type: String, required: true },
    docStatus:   { type: String, required: true },
    docOwnerId:  { type: Number, required: true },
});
const emit = defineEmits(['place']);

const pageInst = usePage();
const currentUserId = computed(() => pageInst.props.auth.user.id);
const visible = computed(() => props.annotations.filter(a => a.page === props.page));

function canManipulate(a) {
    if (a.user?.id === currentUserId.value) return a.route_status !== 'completed';
    if (props.docOwnerId === currentUserId.value && props.docStatus === 'draft') return true;
    return false;
}

function handleClick(e) {
    if (! props.canPlace || ! props.pending) return;
    const rect = e.currentTarget.getBoundingClientRect();
    const x_pct = ((e.clientX - rect.left) / rect.width)  * 100;
    const y_pct = ((e.clientY - rect.top)  / rect.height) * 100;
    emit('place', { x_pct, y_pct, page: props.page });
}

function onAnnotationUpdate(a, geometry) {
    router.patch(
        route('documents.annotations.update', { document: props.docUuid, annotation: a.id }),
        geometry,
        { preserveScroll: true, preserveState: true },
    );
}

function onAnnotationDelete(a) {
    if (! confirm('Remove this annotation?')) return;
    router.delete(
        route('documents.annotations.destroy', { document: props.docUuid, annotationId: a.id }),
        { preserveScroll: true },
    );
}
</script>

<template>
    <div data-annotation-layer class="absolute inset-0" :class="canPlace ? 'cursor-crosshair' : ''" @click="handleClick">
        <DraggableAnnotation v-for="a in visible" :key="a.id"
            :annotation="a"
            :can-manipulate="canManipulate(a)"
            @update="g => onAnnotationUpdate(a, g)"
            @delete="onAnnotationDelete(a)" />
    </div>
</template>
```

- [ ] **Step 2: Update `Show.vue`** — find the `<AnnotationLayer ... />` element and pass the new props:

```vue
                            <AnnotationLayer
                                :annotations="D.annotations"
                                :page="page"
                                :pageSize="ps"
                                :can-place="!!pendingAnnotation && canAnnotate"
                                :pending="pendingAnnotation"
                                :doc-uuid="D.uuid"
                                :doc-status="D.status"
                                :doc-owner-id="D.owner?.id"
                                @place="placeAnnotation" />
```

- [ ] **Step 3: Eager-load `annotations.route` in `DocumentController::show`** — find the `$document->load([...])` block and add `'annotations.route'` (keep `'annotations.user'`).

- [ ] **Step 4: Update `DocumentAnnotationResource`** — replace `toArray` body with:

```php
return [
    'id'           => $this->id,
    'type'         => $this->type?->value,
    'page'         => $this->page,
    'x_pct'        => $this->x_pct,
    'y_pct'        => $this->y_pct,
    'w_pct'        => $this->w_pct,
    'h_pct'        => $this->h_pct,
    'rotation'     => $this->rotation,
    'data'         => $this->data,
    'user'         => $this->whenLoaded('user', fn () => [
        'id'   => $this->user->id,
        'name' => $this->user->name,
    ]),
    'route_status' => $this->route?->status?->value,
];
```

- [ ] **Step 5: Browser smoke test.** Run `npm run dev` + `php artisan serve`, open a Draft doc, place a signature, drag/resize/rotate/delete it. Reload — geometry persists.
- [ ] **Step 6:** Commit `feat(documents): wire manipulable annotations into the viewer`.

---

## Phase 3 — Stamp Asset Library

**Goal:** Let users upload PNG stamps to a personal / department / organization library and pick them when annotating, alongside the existing text stamps.

### Task 3.1: `AssetOwnerScope` enum

**Files:** Create `app/Enums/AssetOwnerScope.php`

- [ ] **Step 1: Create the enum** (shared by Phases 3, 4, 5):

```php
<?php

namespace App\Enums;

enum AssetOwnerScope: string
{
    case Personal     = 'personal';
    case Department   = 'department';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Personal     => 'Personal',
            self::Department   => 'Department',
            self::Organization => 'Organization',
        };
    }
}
```

- [ ] **Step 2:** Commit `feat(documents): AssetOwnerScope enum`.

---

### Task 3.2: `stamp_assets` migration

**Files:** Create `database/migrations/<ts>_create_stamp_assets_table.php`

- [ ] **Step 1:** Create the migration (use the next free `2026_06_10_xxxxxx` timestamp):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stamp_assets', function (Blueprint $t) {
            $t->id();
            $t->string('owner_scope', 20);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name');
            $t->string('storage_path');
            $t->string('mime', 64);
            $t->decimal('default_w_pct', 5, 2)->default(18);
            $t->decimal('default_h_pct', 5, 2)->default(6);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['owner_scope', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_assets');
    }
};
```

- [ ] **Step 2:** Run `php artisan migrate` — expect "Migrated".
- [ ] **Step 3:** Commit `feat(documents): stamp_assets table`.

---

### Task 3.3: `StampAsset` model + factory

**Files:**
- Create: `app/Models/StampAsset.php`
- Create: `database/factories/StampAssetFactory.php`

- [ ] **Step 1: Model**

```php
<?php

namespace App\Models;

use App\Enums\AssetOwnerScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StampAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_scope', 'owner_id', 'name', 'storage_path', 'mime',
        'default_w_pct', 'default_h_pct', 'created_by',
    ];

    protected $casts = [
        'owner_scope'   => AssetOwnerScope::class,
        'default_w_pct' => 'float',
        'default_h_pct' => 'float',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 2: Factory**

```php
<?php

namespace Database\Factories;

use App\Models\StampAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StampAssetFactory extends Factory
{
    protected $model = StampAsset::class;

    public function definition(): array
    {
        return [
            'owner_scope'   => 'personal',
            'owner_id'      => User::factory(),
            'name'          => 'Approved Stamp',
            'storage_path'  => 'assets/stamps/test.png',
            'mime'          => 'image/png',
            'default_w_pct' => 18,
            'default_h_pct' => 6,
            'created_by'    => User::factory(),
        ];
    }
}
```

- [ ] **Step 3:** Commit `feat(documents): StampAsset model + factory`.

---

### Task 3.4: `StoreStampAssetRequest`

**Files:** Create `app/Http/Requests/DocumentAssets/StoreStampAssetRequest.php`

- [ ] **Step 1: Create**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\DocumentAssets;

use App\Enums\AssetOwnerScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreStampAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\StampAsset::class) === true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:120'],
            'owner_scope'   => ['required', new Enum(AssetOwnerScope::class)],
            'owner_id'      => [
                Rule::requiredIf(fn () => $this->input('owner_scope') === AssetOwnerScope::Department->value),
                'nullable', 'integer',
            ],
            'file'          => ['required', 'file', 'mimes:png', 'max:1024'], // 1 MB
            'default_w_pct' => ['nullable', 'numeric', 'between:4,80'],
            'default_h_pct' => ['nullable', 'numeric', 'between:4,80'],
        ];
    }
}
```

- [ ] **Step 2:** Commit `feat(documents): StoreStampAssetRequest`.

---

### Task 3.5: `StampAssetPolicy` and AuthServiceProvider registration

**Files:**
- Create: `app/Policies/StampAssetPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`

The rules:
- `viewAny`: any authenticated user.
- `create`: anyone may create `personal` scope; `department` scope requires the user to belong to that department (`$user->employee?->department_id === $owner_id`); `organization` requires `document_assets.manage`.
- `delete`: creator OR `document_assets.manage`.

- [ ] **Step 1: Create the policy**

```php
<?php

namespace App\Policies;

use App\Enums\AssetOwnerScope;
use App\Models\StampAsset;
use App\Models\User;
use Illuminate\Http\Request;

class StampAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user, ?Request $request = null): bool
    {
        // For controller-side checks before model instantiation we accept the
        // request's input. The form request also validates this.
        $request ??= request();
        $scope = $request?->input('owner_scope');
        $ownerId = $request?->input('owner_id');

        return match ($scope) {
            AssetOwnerScope::Personal->value     => true,
            AssetOwnerScope::Department->value   => $user->employee?->department_id !== null
                                                    && (int) $ownerId === $user->employee->department_id,
            AssetOwnerScope::Organization->value => $user->hasPermission('document_assets.manage'),
            default                              => false,
        };
    }

    public function delete(User $user, StampAsset $asset): bool
    {
        if ($user->hasPermission('document_assets.manage')) {
            return true;
        }
        return $asset->created_by === $user->id;
    }
}
```

- [ ] **Step 2: Register the policy.** In `app/Providers/AuthServiceProvider.php`, in the `$policies` array property, add:

```php
        \App\Models\StampAsset::class => \App\Policies\StampAssetPolicy::class,
```

- [ ] **Step 3:** Commit `feat(documents): StampAssetPolicy`.

---

### Task 3.6: `document_assets.manage` permission

**Files:** Modify `database/seeders/DocumentPermissionsSeeder.php`

- [ ] **Step 1: Add to the PERMISSIONS constant**

```php
        'document_assets.manage'        => ['Documents', 'Manage organization-scope stamps, letterheads and watermarks'],
```

- [ ] **Step 2: Add to manage bundle** — update the `$manageSlugs` array to include the new slug.

- [ ] **Step 3:** Run `php artisan db:seed --class=DocumentPermissionsSeeder` and confirm with `php artisan tinker --execute='dump(\App\Models\Permission::where("slug","document_assets.manage")->first()?->slug);'`.
- [ ] **Step 4:** Commit `feat(documents): document_assets.manage permission`.

---

### Task 3.7: `StampAssetService` and Controller

**Files:**
- Create: `app/Services/StampAssetService.php`
- Create: `app/Http/Controllers/Settings/StampAssetController.php`
- Create: `app/Http/Resources/StampAssetResource.php`

- [ ] **Step 1: Service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StampAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StampAssetService
{
    private const DISK = 'local';

    public function store(array $attrs, UploadedFile $file, User $by): StampAsset
    {
        $path = sprintf('assets/stamps/%s.png', Str::uuid());
        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        return StampAsset::create([
            'owner_scope'   => $attrs['owner_scope'],
            'owner_id'      => $attrs['owner_scope'] === 'personal' ? $by->id : ($attrs['owner_id'] ?? null),
            'name'          => $attrs['name'],
            'storage_path'  => $path,
            'mime'          => 'image/png',
            'default_w_pct' => $attrs['default_w_pct'] ?? 18,
            'default_h_pct' => $attrs['default_h_pct'] ?? 6,
            'created_by'    => $by->id,
        ]);
    }

    public function delete(StampAsset $asset): void
    {
        Storage::disk(self::DISK)->delete($asset->storage_path);
        $asset->delete();
    }

    public function pngBase64(StampAsset $asset): string
    {
        $bytes = Storage::disk(self::DISK)->get($asset->storage_path);
        return 'data:image/png;base64,' . base64_encode($bytes);
    }
}
```

- [ ] **Step 2: Resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StampAssetResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'owner_scope'   => $this->owner_scope?->value,
            'owner_id'      => $this->owner_id,
            'default_w_pct' => $this->default_w_pct,
            'default_h_pct' => $this->default_h_pct,
            'preview_url'   => route('settings.stamps.preview', $this->id),
            'created_at'    => $this->created_at,
        ];
    }
}
```

- [ ] **Step 3: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AssetOwnerScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentAssets\StoreStampAssetRequest;
use App\Http\Resources\StampAssetResource;
use App\Models\StampAsset;
use App\Services\StampAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StampAssetController extends Controller
{
    public function __construct(private readonly StampAssetService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', StampAsset::class);
        $user = $request->user();
        $departmentId = $user->employee?->department_id;

        $assets = StampAsset::query()
            ->where(function ($q) use ($user, $departmentId) {
                $q->where(fn ($x) => $x->where('owner_scope', 'personal')->where('owner_id', $user->id))
                  ->orWhere(fn ($x) => $x->where('owner_scope', 'department')->where('owner_id', $departmentId))
                  ->orWhere('owner_scope', 'organization');
            })
            ->latest()
            ->get();

        return Inertia::render('Settings/Stamps', [
            'assets'       => StampAssetResource::collection($assets),
            'canManageOrg' => $user->hasPermission('document_assets.manage'),
            'departmentId' => $departmentId,
            'activeModule' => 'settings',
        ]);
    }

    public function store(StoreStampAssetRequest $request)
    {
        $asset = $this->service->store($request->validated(), $request->file('file'), $request->user());
        return back()->with('flash.success', "Stamp \"{$asset->name}\" uploaded.");
    }

    public function destroy(StampAsset $asset)
    {
        $this->authorize('delete', $asset);
        $this->service->delete($asset);
        return back()->with('flash.success', 'Stamp removed.');
    }

    public function preview(StampAsset $asset): BinaryFileResponse
    {
        $this->authorize('viewAny', StampAsset::class);
        $path = Storage::disk('local')->path($asset->storage_path);
        return response()->file($path);
    }
}
```

- [ ] **Step 4: Add routes** in `routes/web.php` inside the authenticated middleware group (placement: alongside other Settings routes; if none exist yet, place inside the main `auth` group before the Documents block):

```php
    Route::prefix('settings/stamps')->name('settings.stamps.')->group(function () {
        Route::get('/',              [\App\Http\Controllers\Settings\StampAssetController::class, 'index'])->name('index');
        Route::post('/',             [\App\Http\Controllers\Settings\StampAssetController::class, 'store'])->name('store');
        Route::get('/{asset}/preview', [\App\Http\Controllers\Settings\StampAssetController::class, 'preview'])->name('preview');
        Route::delete('/{asset}',    [\App\Http\Controllers\Settings\StampAssetController::class, 'destroy'])->name('destroy');
    });
```

- [ ] **Step 5:** Commit `feat(documents): stamp asset service + controller + routes`.

---

### Task 3.8: Feature tests for stamp assets

**Files:** Create `tests/Feature/DocumentAssets/StampAssetTest.php`

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\StampAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('user can upload a personal stamp', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('approved.png', 200, 60)->mimeType('image/png');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name'        => 'Approved',
            'owner_scope' => 'personal',
            'file'        => $png,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('stamp_assets', [
        'name'        => 'Approved',
        'owner_scope' => 'personal',
        'owner_id'    => $user->id,
        'created_by'  => $user->id,
    ]);
});

it('rejects non-PNG file', function () {
    $user = User::factory()->create();
    $jpg  = UploadedFile::fake()->image('approved.jpg', 200, 60)->mimeType('image/jpeg');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name' => 'Approved', 'owner_scope' => 'personal', 'file' => $jpg,
        ])
        ->assertSessionHasErrors('file');
});

it('rejects oversized PNG (>1 MB)', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->create('big.png', 1500, 'image/png');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name' => 'Big', 'owner_scope' => 'personal', 'file' => $png,
        ])
        ->assertSessionHasErrors('file');
});

it('blocks org-scope upload without document_assets.manage', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('a.png', 200, 60)->mimeType('image/png');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name' => 'Org', 'owner_scope' => 'organization', 'file' => $png,
        ])
        ->assertForbidden();
});

it('allows org-scope upload with document_assets.manage', function () {
    $user = User::factory()->create(['permissions' => ['document_assets.manage']]);
    $png  = UploadedFile::fake()->image('a.png', 200, 60)->mimeType('image/png');

    $this->actingAs($user)
        ->post(route('settings.stamps.store'), [
            'name' => 'Org', 'owner_scope' => 'organization', 'file' => $png,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('stamp_assets', [
        'name' => 'Org', 'owner_scope' => 'organization',
    ]);
});

it('allows department-scope upload only by a member of that department', function () {
    $dept = Department::factory()->create();
    $member = User::factory()->create();
    Employee::factory()->create(['user_id' => $member->id, 'department_id' => $dept->id]);
    $outsider = User::factory()->create();
    $png = UploadedFile::fake()->image('d.png', 200, 60)->mimeType('image/png');

    $this->actingAs($outsider)
        ->post(route('settings.stamps.store'), [
            'name' => 'Dept', 'owner_scope' => 'department', 'owner_id' => $dept->id, 'file' => $png,
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('settings.stamps.store'), [
            'name' => 'Dept', 'owner_scope' => 'department', 'owner_id' => $dept->id, 'file' => $png,
        ])
        ->assertRedirect();
});

it('creator can delete their stamp', function () {
    $user  = User::factory()->create();
    $asset = StampAsset::factory()->create(['created_by' => $user->id, 'owner_id' => $user->id]);

    $this->actingAs($user)->delete(route('settings.stamps.destroy', $asset->id))->assertRedirect();
    $this->assertDatabaseMissing('stamp_assets', ['id' => $asset->id]);
});

it('non-creator without manage cannot delete', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $asset = StampAsset::factory()->create(['created_by' => $owner->id, 'owner_id' => $owner->id]);

    $this->actingAs($other)->delete(route('settings.stamps.destroy', $asset->id))->assertForbidden();
});
```

- [ ] **Step 2:** Run `php artisan test tests/Feature/DocumentAssets/StampAssetTest.php` — expect PASS.
- [ ] **Step 3:** Commit `test(documents): stamp asset coverage`.

---

### Task 3.9: `Settings/Stamps.vue` admin page

**Files:** Create `resources/js/Pages/Settings/Stamps.vue`

- [ ] **Step 1: Create the page**

```vue
<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    assets:       { type: Object, required: true },
    canManageOrg: { type: Boolean, default: false },
    departmentId: { type: Number, default: null },
    activeModule: { type: String, default: 'settings' },
});

const scope = ref('personal');
const form  = useForm({ name: '', owner_scope: 'personal', owner_id: null, file: null });

function submit() {
    form.transform((data) => ({
        ...data,
        owner_id: data.owner_scope === 'department' ? props.departmentId : null,
    })).post(route('settings.stamps.store'), {
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
}

function remove(asset) {
    if (! confirm(`Remove stamp "${asset.name}"?`)) return;
    router.delete(route('settings.stamps.destroy', asset.id), { preserveScroll: true });
}

const SCOPES = ['personal', 'department', 'organization'];
</script>

<template>
    <Head title="Stamp Library" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">SETTINGS · STAMPS</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Stamp library</h1>
                <p class="mt-1 text-[13px] text-on-surface-variant">Upload PNG stamps you can place on documents.</p>
            </div>
        </Teleport>

        <form @submit.prevent="submit" enctype="multipart/form-data"
              class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card mb-6 grid md:grid-cols-4 gap-3">
            <input v-model="form.name" required maxlength="120" placeholder="Stamp name"
                   class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
            <select v-model="form.owner_scope" aria-label="Scope"
                    class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                <option v-for="s in SCOPES" :key="s" :value="s"
                        :disabled="(s === 'organization' && !canManageOrg) || (s === 'department' && !departmentId)">
                    {{ s }}
                </option>
            </select>
            <input type="file" required accept="image/png" @change="e => form.file = e.target.files[0]"
                   class="text-[12px]" />
            <button type="submit" :disabled="form.processing"
                    class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                {{ form.processing ? 'Uploading…' : 'Upload PNG' }}
            </button>
            <p v-if="form.errors.file" class="md:col-span-4 text-rose-600 text-xs">{{ form.errors.file }}</p>
        </form>

        <div class="flex gap-2 mb-3">
            <button v-for="s in SCOPES" :key="s" @click="scope = s"
                    :class="['rounded-xl px-3 py-1.5 text-[11px] font-black uppercase tracking-widest',
                             scope === s ? 'bg-secondary/10 text-secondary' : 'text-on-surface-variant']">
                {{ s }}
            </button>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div v-for="a in assets.data.filter(x => x.owner_scope === scope)" :key="a.id"
                 class="rounded-xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <img :src="a.preview_url" :alt="a.name" class="h-16 w-full object-contain bg-white rounded" />
                <p class="mt-2 text-[12px] font-black truncate">{{ a.name }}</p>
                <div class="flex justify-end mt-1">
                    <button @click="remove(a)" class="text-[11px] font-black text-rose-600">Remove</button>
                </div>
            </div>
            <p v-if="!assets.data.some(x => x.owner_scope === scope)" class="col-span-full text-center text-on-surface-variant text-[12px] py-6">
                No {{ scope }} stamps yet.
            </p>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Add nav entry** in `resources/js/Layouts/AuthenticatedLayout.vue` under the Settings group:

```js
        { label: 'Stamps',      icon: 'verified',      href: route('settings.stamps.index'),      perm: null },
```

(Match the surrounding pattern; if entries use a different shape, follow it.)

- [ ] **Step 3:** Commit `feat(documents): stamp library admin page`.

---

### Task 3.10: Extend `StampPicker.vue` with library tabs

**Files:**
- Modify: `resources/js/Components/Documents/StampPicker.vue`

The current `StampPicker` only emits `{ text, color }`. After this task, picking from the library emits `{ png_base64, asset_id }` so the existing `placeAnnotation` in `Show.vue` saves the PNG annotation that the renderer already supports.

- [ ] **Step 1: Replace the file**

```vue
<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    presets: {
        type: Array,
        default: () => [
            { text: 'APPROVED',     color: '#059669' },
            { text: 'RECEIVED',     color: '#1a237e' },
            { text: 'FOR ACTION',   color: '#d97706' },
            { text: 'CONFIDENTIAL', color: '#dc2626' },
        ],
    },
});

const emit = defineEmits(['stamp', 'cancel']);

const tab = ref('library');
const assets = ref([]);
const loading = ref(false);
const customText  = ref('');
const customColor = ref('#cc0000');

async function fetchAssets() {
    loading.value = true;
    try {
        // The settings index returns an Inertia page; for the picker we hit a
        // small JSON endpoint that returns just the user's accessible assets.
        const res = await axios.get(route('settings.stamps.index'), { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': '0', Accept: 'application/json' } });
        assets.value = res.data?.props?.assets?.data ?? [];
    } catch (e) {
        assets.value = [];
    } finally {
        loading.value = false;
    }
}

onMounted(fetchAssets);

async function pickAsset(asset) {
    // Convert the asset preview to a base64 PNG payload so the annotation
    // renderer can burn it in without a server round-trip.
    const res = await fetch(asset.preview_url);
    const blob = await res.blob();
    const reader = new FileReader();
    reader.onloadend = () => {
        emit('stamp', { png_base64: reader.result, asset_id: asset.id, w_pct: asset.default_w_pct, h_pct: asset.default_h_pct });
    };
    reader.readAsDataURL(blob);
}
</script>

<template>
    <div class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest rounded-2xl border border-outline-variant shadow-card-hover p-5 w-full max-w-xl">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary mb-1">Place a stamp</p>
            <h2 class="text-lg font-black text-primary leading-tight mb-3">Choose a stamp</h2>

            <div class="flex gap-1 mb-3 border-b border-outline-variant/40">
                <button @click="tab = 'library'"
                        :class="['px-3 py-1.5 text-[11px] font-black uppercase tracking-widest border-b-2',
                                 tab === 'library' ? 'border-secondary text-secondary' : 'border-transparent text-on-surface-variant']">
                    My library
                </button>
                <button @click="tab = 'text'"
                        :class="['px-3 py-1.5 text-[11px] font-black uppercase tracking-widest border-b-2',
                                 tab === 'text' ? 'border-secondary text-secondary' : 'border-transparent text-on-surface-variant']">
                    Text stamp
                </button>
            </div>

            <div v-if="tab === 'library'">
                <p v-if="loading" class="text-[12px] text-on-surface-variant">Loading…</p>
                <div v-else-if="assets.length" class="grid grid-cols-3 gap-2">
                    <button v-for="a in assets" :key="a.id" @click="pickAsset(a)"
                            class="rounded-lg border border-outline-variant p-2 hover:border-secondary transition-colors">
                        <img :src="a.preview_url" :alt="a.name" class="h-14 w-full object-contain bg-white rounded" />
                        <p class="mt-1 text-[11px] font-bold truncate">{{ a.name }}</p>
                    </button>
                </div>
                <div v-else class="text-center py-6 text-[12px] text-on-surface-variant">
                    No stamps yet — <a :href="route('settings.stamps.index')" class="text-secondary font-black underline">upload one</a>.
                </div>
            </div>

            <div v-if="tab === 'text'">
                <div class="grid grid-cols-2 gap-2">
                    <button v-for="p in presets" :key="p.text"
                            @click="emit('stamp', { text: p.text, color: p.color })"
                            class="flex items-center justify-center border-2 rounded-lg px-3 py-3 text-[13px] font-black"
                            :style="{ color: p.color, borderColor: p.color }">{{ p.text }}</button>
                </div>
                <div class="mt-4 border-t border-outline-variant/40 pt-3">
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
            </div>

            <div class="mt-4 flex justify-end">
                <button @click="emit('cancel')" class="rounded-lg border border-outline-variant px-4 py-2 text-[13px] font-bold">Cancel</button>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Update `Show.vue::onStamp`** to handle the new payload shape:

```js
function onStamp(payload) {
    // Library stamps come with { png_base64, asset_id, w_pct, h_pct };
    // text stamps come with { text, color }. Renderer handles both via
    // annotation.data — DocumentRenderService already burns png_base64 stamps.
    if (payload.png_base64) {
        pendingAnnotation.value = {
            type: 'stamp',
            data: { png_base64: payload.png_base64, asset_id: payload.asset_id },
            w_pct: payload.w_pct, h_pct: payload.h_pct,
        };
    } else {
        pendingAnnotation.value = { type: 'stamp', data: { text: payload.text, color: payload.color } };
    }
    showStamp.value = false;
}
```

Also update `placeAnnotation` in `Show.vue` to honor optional `w_pct/h_pct` from the pending annotation:

```js
function placeAnnotation({ x_pct, y_pct, page: pageNo }) {
    if (! pendingAnnotation.value) return;
    const fromPending = pendingAnnotation.value;
    const dimensions = fromPending.w_pct
        ? { w_pct: fromPending.w_pct, h_pct: fromPending.h_pct }
        : (fromPending.type === 'stamp' ? { w_pct: 18, h_pct: 6 } : { w_pct: 22, h_pct: 8 });

    router.post(route('documents.annotations.store', D.value.uuid), {
        type:  fromPending.type,
        page:  pageNo,
        x_pct, y_pct,
        ...dimensions,
        data:  fromPending.data,
    }, { preserveScroll: true, onSuccess: () => { pendingAnnotation.value = null; } });
}
```

- [ ] **Step 3: Browser smoke test.** Upload a PNG via Settings → Stamps, then on a Draft doc open the Add Stamp dialog → library tab → click it → place it. Refresh: stamp appears.
- [ ] **Step 4:** Commit `feat(documents): StampPicker library + Show.vue payload handling`.

---

## Phase 4 — Letterhead Templates

**Goal:** Replace the single hardcoded `public/img/letterhead.png` with user-managed letterhead templates. Composer gets a dropdown; existing docs keep the seeded default.

### Task 4.1: `letterhead_templates` migration

**Files:** Create `database/migrations/<ts>_create_letterhead_templates_table.php`

- [ ] **Step 1: Create**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('letterhead_templates', function (Blueprint $t) {
            $t->id();
            $t->string('owner_scope', 20);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name');
            $t->string('storage_path');
            $t->string('mime', 64);
            $t->smallInteger('header_height_mm')->default(36);
            $t->boolean('is_default')->default(false);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['owner_scope', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letterhead_templates');
    }
};
```

- [ ] **Step 2:** Run `php artisan migrate`. Commit `feat(documents): letterhead_templates table`.

---

### Task 4.2: Add `letterhead_id` to `documents`

**Files:** Create `database/migrations/<ts>_add_letterhead_id_to_documents_table.php`

- [ ] **Step 1: Create**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignId('letterhead_id')->nullable()->after('confidentiality')->constrained('letterhead_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('letterhead_id');
        });
    }
};
```

- [ ] **Step 2:** Update `Document` model — add `'letterhead_id'` to `$fillable` and add the relation:

```php
    public function letterhead(): BelongsTo
    {
        return $this->belongsTo(LetterheadTemplate::class, 'letterhead_id');
    }
```

- [ ] **Step 3:** Run `php artisan migrate`. Commit `feat(documents): letterhead_id on documents`.

---

### Task 4.3: `LetterheadTemplate` model + factory

**Files:**
- Create: `app/Models/LetterheadTemplate.php`
- Create: `database/factories/LetterheadTemplateFactory.php`

- [ ] **Step 1: Model**

```php
<?php

namespace App\Models;

use App\Enums\AssetOwnerScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LetterheadTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_scope', 'owner_id', 'name', 'storage_path', 'mime',
        'header_height_mm', 'is_default', 'created_by',
    ];

    protected $casts = [
        'owner_scope'      => AssetOwnerScope::class,
        'is_default'       => 'boolean',
        'header_height_mm' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 2: Factory**

```php
<?php

namespace Database\Factories;

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LetterheadTemplateFactory extends Factory
{
    protected $model = LetterheadTemplate::class;

    public function definition(): array
    {
        return [
            'owner_scope'      => 'personal',
            'owner_id'         => User::factory(),
            'name'             => 'My Letterhead',
            'storage_path'     => 'assets/letterheads/test.png',
            'mime'             => 'image/png',
            'header_height_mm' => 36,
            'is_default'       => false,
            'created_by'       => User::factory(),
        ];
    }
}
```

- [ ] **Step 3:** Commit `feat(documents): LetterheadTemplate model + factory`.

---

### Task 4.4: `LetterheadTemplatePolicy`

**Files:**
- Create: `app/Policies/LetterheadTemplatePolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`

- [ ] **Step 1: Create the policy** (same shape as StampAssetPolicy — same scope rules):

```php
<?php

namespace App\Policies;

use App\Enums\AssetOwnerScope;
use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Http\Request;

class LetterheadTemplatePolicy
{
    public function viewAny(User $user): bool { return true; }

    public function create(User $user, ?Request $request = null): bool
    {
        $request ??= request();
        $scope = $request?->input('owner_scope');
        $ownerId = $request?->input('owner_id');

        return match ($scope) {
            AssetOwnerScope::Personal->value     => true,
            AssetOwnerScope::Department->value   => $user->employee?->department_id !== null
                                                    && (int) $ownerId === $user->employee->department_id,
            AssetOwnerScope::Organization->value => $user->hasPermission('document_assets.manage'),
            default                              => false,
        };
    }

    public function delete(User $user, LetterheadTemplate $template): bool
    {
        if ($template->is_default) return false; // never delete the seeded default
        if ($user->hasPermission('document_assets.manage')) return true;
        return $template->created_by === $user->id;
    }
}
```

- [ ] **Step 2: Register** in `AuthServiceProvider::$policies`:

```php
        \App\Models\LetterheadTemplate::class => \App\Policies\LetterheadTemplatePolicy::class,
```

- [ ] **Step 3:** Commit `feat(documents): LetterheadTemplatePolicy`.

---

### Task 4.5: Request, Resource, Service, Controller, Routes

**Files:**
- Create: `app/Http/Requests/DocumentAssets/StoreLetterheadRequest.php`
- Create: `app/Http/Resources/LetterheadTemplateResource.php`
- Create: `app/Services/LetterheadTemplateService.php`
- Create: `app/Http/Controllers/Settings/LetterheadTemplateController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\DocumentAssets;

use App\Enums\AssetOwnerScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreLetterheadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\LetterheadTemplate::class) === true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:120'],
            'owner_scope'      => ['required', new Enum(AssetOwnerScope::class)],
            'owner_id'         => [Rule::requiredIf(fn () => $this->input('owner_scope') === 'department'), 'nullable', 'integer'],
            'file'             => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:3072'], // 3 MB
            'header_height_mm' => ['nullable', 'integer', 'between:20,80'],
        ];
    }
}
```

- [ ] **Step 2: Resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LetterheadTemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'owner_scope'      => $this->owner_scope?->value,
            'owner_id'         => $this->owner_id,
            'header_height_mm' => $this->header_height_mm,
            'is_default'       => $this->is_default,
            'preview_url'      => route('settings.letterheads.preview', $this->id),
        ];
    }
}
```

- [ ] **Step 3: Service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LetterheadTemplateService
{
    private const DISK = 'local';

    public function store(array $attrs, UploadedFile $file, User $by): LetterheadTemplate
    {
        $ext  = strtolower($file->getClientOriginalExtension()) ?: 'png';
        $path = sprintf('assets/letterheads/%s.%s', Str::uuid(), $ext);
        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        return LetterheadTemplate::create([
            'owner_scope'      => $attrs['owner_scope'],
            'owner_id'         => $attrs['owner_scope'] === 'personal' ? $by->id : ($attrs['owner_id'] ?? null),
            'name'             => $attrs['name'],
            'storage_path'     => $path,
            'mime'             => $file->getClientMimeType(),
            'header_height_mm' => $attrs['header_height_mm'] ?? 36,
            'is_default'       => false,
            'created_by'       => $by->id,
        ]);
    }

    public function delete(LetterheadTemplate $template): void
    {
        Storage::disk(self::DISK)->delete($template->storage_path);
        $template->delete();
    }

    public function absolutePath(LetterheadTemplate $template): string
    {
        return Storage::disk(self::DISK)->path($template->storage_path);
    }
}
```

- [ ] **Step 4: Controller** (mirrors StampAssetController)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentAssets\StoreLetterheadRequest;
use App\Http\Resources\LetterheadTemplateResource;
use App\Models\LetterheadTemplate;
use App\Services\LetterheadTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LetterheadTemplateController extends Controller
{
    public function __construct(private readonly LetterheadTemplateService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', LetterheadTemplate::class);
        $user = $request->user();
        $departmentId = $user->employee?->department_id;

        $items = LetterheadTemplate::query()
            ->where(function ($q) use ($user, $departmentId) {
                $q->where(fn ($x) => $x->where('owner_scope', 'personal')->where('owner_id', $user->id))
                  ->orWhere(fn ($x) => $x->where('owner_scope', 'department')->where('owner_id', $departmentId))
                  ->orWhere('owner_scope', 'organization');
            })
            ->latest()
            ->get();

        return Inertia::render('Settings/Letterheads', [
            'templates'    => LetterheadTemplateResource::collection($items),
            'canManageOrg' => $user->hasPermission('document_assets.manage'),
            'departmentId' => $departmentId,
            'activeModule' => 'settings',
        ]);
    }

    public function store(StoreLetterheadRequest $request)
    {
        $tpl = $this->service->store($request->validated(), $request->file('file'), $request->user());
        return back()->with('flash.success', "Letterhead \"{$tpl->name}\" uploaded.");
    }

    public function destroy(LetterheadTemplate $template)
    {
        $this->authorize('delete', $template);
        $this->service->delete($template);
        return back()->with('flash.success', 'Letterhead removed.');
    }

    public function preview(LetterheadTemplate $template): BinaryFileResponse
    {
        $this->authorize('viewAny', LetterheadTemplate::class);
        return response()->file(Storage::disk('local')->path($template->storage_path));
    }
}
```

- [ ] **Step 5: Routes** (group alongside the stamps routes added in Phase 3)

```php
    Route::prefix('settings/letterheads')->name('settings.letterheads.')->group(function () {
        Route::get('/',                  [\App\Http\Controllers\Settings\LetterheadTemplateController::class, 'index'])->name('index');
        Route::post('/',                 [\App\Http\Controllers\Settings\LetterheadTemplateController::class, 'store'])->name('store');
        Route::get('/{template}/preview',[\App\Http\Controllers\Settings\LetterheadTemplateController::class, 'preview'])->name('preview');
        Route::delete('/{template}',     [\App\Http\Controllers\Settings\LetterheadTemplateController::class, 'destroy'])->name('destroy');
    });
```

- [ ] **Step 6:** Commit `feat(documents): letterhead template service + controller + routes`.

---

### Task 4.6: `DefaultDocumentAssetsSeeder` for the org default

**Files:**
- Create: `database/seeders/DefaultDocumentAssetsSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php` (call new seeder)

- [ ] **Step 1: Create the seeder**

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DefaultDocumentAssetsSeeder extends Seeder
{
    public function run(): void
    {
        // Seed the default org letterhead from the legacy public/img/letterhead.png
        // so existing docs that have letterhead_id IS NULL still get a sane header.
        if (LetterheadTemplate::where('is_default', true)->exists()) {
            return;
        }
        $src = public_path('img/letterhead.png');
        if (! is_file($src)) {
            $this->command?->warn('public/img/letterhead.png not found; skipping default letterhead seed.');
            return;
        }
        $dst = 'assets/letterheads/default-cihrm-ghana.png';
        Storage::disk('local')->put($dst, file_get_contents($src));

        // Choose a system user as creator: the first super_admin or the first user.
        $creator = User::query()->orderBy('id')->first();
        if (! $creator) return;

        LetterheadTemplate::create([
            'owner_scope'      => 'organization',
            'owner_id'         => null,
            'name'             => 'CIHRM-GHANA (default)',
            'storage_path'     => $dst,
            'mime'             => 'image/png',
            'header_height_mm' => 36,
            'is_default'       => true,
            'created_by'       => $creator->id,
        ]);
    }
}
```

- [ ] **Step 2: Wire into `DatabaseSeeder::run()`** — append:

```php
        $this->call(DefaultDocumentAssetsSeeder::class);
```

- [ ] **Step 3:** Run `php artisan db:seed --class=DefaultDocumentAssetsSeeder` once locally and confirm the row exists.
- [ ] **Step 4:** Commit `feat(documents): seed default org letterhead`.

---

### Task 4.7: Composer service uses selected letterhead

**Files:**
- Modify: `app/Http/Requests/Documents/ComposeDocumentRequest.php` (add optional `letterhead_id`)
- Modify: `app/Services/DocumentComposerService.php`

- [ ] **Step 1: Update `ComposeDocumentRequest::rules()`** — add:

```php
            'letterhead_id' => ['nullable', 'integer', 'exists:letterhead_templates,id'],
```

- [ ] **Step 2: Update `DocumentComposerService::compose()`** signature flow — pass `letterhead_id` through:

After the `Document::create([...])` block, before `renderHtmlToPdf(...)`:

```php
            $letterhead = ! empty($attrs['letterhead_id'])
                ? \App\Models\LetterheadTemplate::find($attrs['letterhead_id'])
                : \App\Models\LetterheadTemplate::where('is_default', true)->first();
            $doc->update(['letterhead_id' => $letterhead?->id]);
```

Then replace the call:

```php
            $pdfPath = $this->renderHtmlToPdf(
                $doc,
                $attrs['title'],
                $this->sanitizeHtml($attrs['body_html']),
                $letterhead,
            );
```

- [ ] **Step 3: Update `renderHtmlToPdf()` signature** to accept `?LetterheadTemplate $letterhead` instead of the boolean. Replace the existing letterhead-block code so it uses the template image when present:

```php
    private function renderHtmlToPdf(Document $doc, string $title, string $bodyHtml, ?\App\Models\LetterheadTemplate $letterhead): string
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('CIHRMS');
        $pdf->SetAuthor($doc->owner?->name ?? 'CIHRMS');
        $pdf->SetTitle($title);
        $pdf->SetMargins(20, $letterhead ? max(20, $letterhead->header_height_mm) : 18, 20);
        $pdf->setHeaderMargin($letterhead ? 8 : 0);
        $pdf->setAutoPageBreak(true, 18);

        if ($letterhead) {
            $absPath = \Illuminate\Support\Facades\Storage::disk('local')->path($letterhead->storage_path);
            // TCPDF lets us hook Header() via setHeaderData (image filename + width). We pass
            // the absolute path; TCPDF accepts a public path or absolute file path.
            $pdf->SetHeaderData($absPath, $letterhead->header_height_mm, '', '');
            $pdf->setPrintHeader(true);
            $pdf->setHeaderFont(['helvetica', '', 9]);
        } else {
            $pdf->setPrintHeader(false);
        }
        $pdf->setPrintFooter(false);

        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 11);
        $pdf->writeHTML($bodyHtml, true, false, true, false, '');

        $out = tempnam(sys_get_temp_dir(), 'comp') . '.pdf';
        $pdf->Output($out, 'F');
        return $out;
    }
```

- [ ] **Step 4:** Commit `feat(documents): composer honors letterhead template`.

---

### Task 4.8: Composer page dropdown

**Files:** Modify `resources/js/Pages/Documents/Compose.vue`

- [ ] **Step 1: Replace the boolean letterhead toggle** with a dropdown. In the `<script setup>` block, fetch templates on mount:

```js
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';

const templates = ref([]);
async function loadTemplates() {
    const res = await axios.get(route('settings.letterheads.index'), { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': '0', Accept: 'application/json' } });
    templates.value = res.data?.props?.templates?.data ?? [];
    // Default selection: the first letterhead with is_default true, else none.
    if (! form.letterhead_id) {
        const def = templates.value.find(t => t.is_default);
        if (def) form.letterhead_id = def.id;
    }
}
onMounted(loadTemplates);
```

Update the `form = useForm({...})` to include `letterhead_id: null` and **remove** the `letterhead: true` boolean.

- [ ] **Step 2: Update the toggle UI in the header** — replace the "Attach letterhead" checkbox label with:

```vue
                    <select v-model="form.letterhead_id" aria-label="Letterhead template"
                            class="rounded-xl border border-outline-variant px-3 py-2 text-[12px] font-black">
                        <option :value="null">No letterhead</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">
                            {{ t.name }} ({{ t.owner_scope }})
                        </option>
                    </select>
```

- [ ] **Step 3: Update live preview** — `previewHtml` should use the selected template's preview URL:

```js
const selectedTemplate = computed(() => templates.value.find(t => t.id === form.letterhead_id));

const previewHtml = computed(() => {
    const lh = selectedTemplate.value
        ? `<header style="border-bottom:1px solid #c9a227;padding-bottom:8px;margin-bottom:18px;">
             <img src="${selectedTemplate.value.preview_url}" style="width:100%;max-height:${selectedTemplate.value.header_height_mm * 3}px;object-fit:contain;" />
           </header>`
        : '';
    return `<!doctype html><html><head><meta charset="utf-8"><style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #1a1a1a; line-height: 1.5; padding: 18mm 20mm 20mm; margin: 0; background: #fff; }
        h1, h2, h3 { color: #0d1452; } p { margin: 0 0 10px; }
        ul, ol { margin: 0 0 10px 20px; }
        blockquote { border-left: 3px solid #ccc; padding-left: 10px; color: #555; }
        hr { border: 0; border-top: 1px solid #ddd; margin: 14px 0; }
        a { color: #205295; }
      </style></head><body>${lh}${form.body_html}</body></html>`;
});
```

- [ ] **Step 4:** Commit `feat(documents): compose page letterhead dropdown`.

---

### Task 4.9: Edit drawer letterhead picker

**Files:** Modify `resources/js/Pages/Documents/Show.vue`, `app/Http/Requests/Documents/UpdateDocumentRequest.php`, `app/Http/Resources/DocumentResource.php`, `app/Services/DocumentService.php`

- [ ] **Step 1: Allow `letterhead_id` in `UpdateDocumentRequest::rules`** — add:

```php
            'letterhead_id'   => ['sometimes', 'nullable', 'integer', 'exists:letterhead_templates,id'],
```

- [ ] **Step 2: Allow it in `DocumentService::updateMetadata`** — extend `$allowed`:

```php
        $allowed = ['title', 'description', 'confidentiality', 'tags', 'letterhead_id'];
```

- [ ] **Step 3: Expose in `DocumentResource::toArray`** — add:

```php
            'letterhead_id' => $this->letterhead_id,
```

- [ ] **Step 4: Show.vue** — extend `editForm` to include `letterhead_id`, fetch templates on mount, and add a select inside the Edit drawer. Pattern mirrors Compose.

- [ ] **Step 5:** Commit `feat(documents): edit drawer letterhead picker`.

---

### Task 4.10: Tests

**Files:**
- Create: `tests/Feature/DocumentAssets/LetterheadTemplateTest.php`
- Create: `tests/Feature/Documents/ComposeWithLetterheadTest.php`

- [ ] **Step 1: Letterhead CRUD test** (`LetterheadTemplateTest.php`)

```php
<?php

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('user can upload a personal letterhead', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('lh.png', 1200, 200);

    $this->actingAs($user)
        ->post(route('settings.letterheads.store'), [
            'name' => 'My LH', 'owner_scope' => 'personal', 'file' => $png,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('letterhead_templates', ['name' => 'My LH', 'owner_scope' => 'personal']);
});

it('cannot delete the seeded default letterhead', function () {
    $user = User::factory()->create(['permissions' => ['document_assets.manage']]);
    $tpl  = LetterheadTemplate::factory()->create([
        'is_default' => true, 'owner_scope' => 'organization', 'owner_id' => null,
    ]);
    $this->actingAs($user)
        ->delete(route('settings.letterheads.destroy', $tpl->id))
        ->assertForbidden();
});

it('org-scope upload requires document_assets.manage', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('lh.png', 1200, 200);
    $this->actingAs($user)
        ->post(route('settings.letterheads.store'), [
            'name' => 'Org', 'owner_scope' => 'organization', 'file' => $png,
        ])
        ->assertForbidden();
});
```

- [ ] **Step 2: Compose-with-letterhead** (`ComposeWithLetterheadTest.php`)

```php
<?php

use App\Models\LetterheadTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('compose attaches the selected letterhead_id', function () {
    Storage::disk('local')->put('assets/letterheads/test.png', file_get_contents(__DIR__.'/../../../public/img/letterhead.png'));
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $tpl   = LetterheadTemplate::factory()->create([
        'owner_scope'  => 'personal',
        'owner_id'     => $owner->id,
        'created_by'   => $owner->id,
        'storage_path' => 'assets/letterheads/test.png',
        'mime'         => 'image/png',
    ]);

    $this->actingAs($owner)
        ->post(route('documents.compose.store'), [
            'title'           => 'Letter',
            'confidentiality' => 'internal',
            'letterhead_id'   => $tpl->id,
            'body_html'       => '<p>Hello</p>',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('documents', ['title' => 'Letter', 'letterhead_id' => $tpl->id]);
});

it('compose falls back to the default letterhead when none selected', function () {
    Storage::disk('local')->put('assets/letterheads/default.png', file_get_contents(__DIR__.'/../../../public/img/letterhead.png'));
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $default = LetterheadTemplate::factory()->create([
        'owner_scope'  => 'organization',
        'owner_id'     => null,
        'is_default'   => true,
        'storage_path' => 'assets/letterheads/default.png',
        'created_by'   => $owner->id,
    ]);

    $this->actingAs($owner)
        ->post(route('documents.compose.store'), [
            'title'           => 'Auto',
            'confidentiality' => 'internal',
            'body_html'       => '<p>Hello</p>',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('documents', ['title' => 'Auto', 'letterhead_id' => $default->id]);
});
```

- [ ] **Step 3:** Run `php artisan test --filter=Letterhead` — expect PASS.
- [ ] **Step 4:** Commit `test(documents): letterhead template coverage`.

---

### Task 4.11: Settings/Letterheads.vue admin page

**Files:** Create `resources/js/Pages/Settings/Letterheads.vue`

- [ ] **Step 1: Create** (mirror of `Settings/Stamps.vue` from Phase 3 — same shape, replace endpoints with `settings.letterheads.*`, accept image/png and image/jpeg, show wider preview thumbnails). Add the same scope filter tabs.

```vue
<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    templates:    { type: Object, required: true },
    canManageOrg: { type: Boolean, default: false },
    departmentId: { type: Number, default: null },
});

const scope = ref('personal');
const form  = useForm({ name: '', owner_scope: 'personal', owner_id: null, header_height_mm: 36, file: null });

function submit() {
    form.transform((d) => ({
        ...d,
        owner_id: d.owner_scope === 'department' ? props.departmentId : null,
    })).post(route('settings.letterheads.store'), {
        forceFormData: true, onSuccess: () => form.reset(),
    });
}

function remove(t) {
    if (! confirm(`Remove letterhead "${t.name}"?`)) return;
    router.delete(route('settings.letterheads.destroy', t.id), { preserveScroll: true });
}

const SCOPES = ['personal', 'department', 'organization'];
</script>

<template>
    <Head title="Letterheads" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">SETTINGS · LETTERHEADS</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Letterhead templates</h1>
                <p class="mt-1 text-[13px] text-on-surface-variant">Upload letterhead banners for the in-portal composer.</p>
            </div>
        </Teleport>

        <form @submit.prevent="submit" enctype="multipart/form-data"
              class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card mb-6 grid md:grid-cols-5 gap-3">
            <input v-model="form.name" required maxlength="120" placeholder="Template name"
                   class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
            <select v-model="form.owner_scope" aria-label="Scope" class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                <option v-for="s in SCOPES" :key="s" :value="s"
                        :disabled="(s === 'organization' && !canManageOrg) || (s === 'department' && !departmentId)">{{ s }}</option>
            </select>
            <input v-model.number="form.header_height_mm" type="number" min="20" max="80"
                   aria-label="Header height mm"
                   class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
            <input type="file" required accept="image/png,image/jpeg"
                   @change="e => form.file = e.target.files[0]" class="text-[12px]" />
            <button type="submit" :disabled="form.processing"
                    class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                {{ form.processing ? 'Uploading…' : 'Upload' }}
            </button>
        </form>

        <div class="flex gap-2 mb-3">
            <button v-for="s in SCOPES" :key="s" @click="scope = s"
                    :class="['rounded-xl px-3 py-1.5 text-[11px] font-black uppercase tracking-widest',
                             scope === s ? 'bg-secondary/10 text-secondary' : 'text-on-surface-variant']">{{ s }}</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div v-for="t in templates.data.filter(x => x.owner_scope === scope)" :key="t.id"
                 class="rounded-xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <img :src="t.preview_url" :alt="t.name" class="w-full max-h-32 object-contain bg-white rounded" />
                <div class="mt-2 flex items-center justify-between">
                    <div>
                        <p class="text-[12px] font-black">{{ t.name }} <span v-if="t.is_default" class="ml-2 text-[10px] uppercase font-black text-emerald-700">default</span></p>
                        <p class="text-[10px] text-on-surface-variant">height {{ t.header_height_mm }} mm</p>
                    </div>
                    <button v-if="!t.is_default" @click="remove(t)" class="text-[11px] font-black text-rose-600">Remove</button>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Add the nav link** under Settings in `AuthenticatedLayout.vue`.
- [ ] **Step 3:** Commit `feat(documents): letterhead admin page`.

---

## Phase 5 — Watermark Templates

**Goal:** Let users upload watermark templates (text or PNG image) and attach one to a document with a `watermark_mode` (`none | on_burn | always`). The renderer honors the per-document override; existing restricted auto-watermark behavior is preserved when no template is set.

### Task 5.1: `DocumentWatermarkMode` enum

**Files:** Create `app/Enums/DocumentWatermarkMode.php`

- [ ] **Step 1: Create**

```php
<?php

namespace App\Enums;

enum DocumentWatermarkMode: string
{
    case None   = 'none';
    case OnBurn = 'on_burn';
    case Always = 'always';
}
```

- [ ] **Step 2:** Commit `feat(documents): DocumentWatermarkMode enum`.

---

### Task 5.2: `watermark_templates` migration

**Files:** Create `database/migrations/<ts>_create_watermark_templates_table.php`

- [ ] **Step 1: Create**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('watermark_templates', function (Blueprint $t) {
            $t->id();
            $t->string('owner_scope', 20);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name');
            $t->string('type', 10);                  // text | image
            $t->string('text')->nullable();
            $t->string('color', 9)->nullable();
            $t->string('storage_path')->nullable();
            $t->string('mime', 64)->nullable();
            $t->decimal('opacity', 3, 2)->default(0.18);
            $t->smallInteger('angle_deg')->default(-30);
            $t->smallInteger('font_size_hint')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['owner_scope', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_templates');
    }
};
```

- [ ] **Step 2:** Run `php artisan migrate`. Commit `feat(documents): watermark_templates table`.

---

### Task 5.3: Add `watermark_id` and `watermark_mode` to `documents`

**Files:** Create `database/migrations/<ts>_add_watermark_to_documents_table.php`

- [ ] **Step 1: Create**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignId('watermark_id')->nullable()->after('letterhead_id')->constrained('watermark_templates')->nullOnDelete();
            $t->string('watermark_mode', 10)->default('on_burn');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('watermark_id');
            $t->dropColumn('watermark_mode');
        });
    }
};
```

- [ ] **Step 2: Update `Document` model** — add to `$fillable`: `'watermark_id'`, `'watermark_mode'`. Add cast: `'watermark_mode' => DocumentWatermarkMode::class`. Add relation:

```php
    public function watermark(): BelongsTo
    {
        return $this->belongsTo(WatermarkTemplate::class, 'watermark_id');
    }
```

- [ ] **Step 3:** Run `php artisan migrate`. Commit `feat(documents): watermark_id + watermark_mode columns`.

---

### Task 5.4: `WatermarkTemplate` model + factory + policy

**Files:**
- Create: `app/Models/WatermarkTemplate.php`
- Create: `database/factories/WatermarkTemplateFactory.php`
- Create: `app/Policies/WatermarkTemplatePolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`

- [ ] **Step 1: Model**

```php
<?php

namespace App\Models;

use App\Enums\AssetOwnerScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatermarkTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_scope', 'owner_id', 'name', 'type', 'text', 'color',
        'storage_path', 'mime', 'opacity', 'angle_deg', 'font_size_hint',
        'created_by',
    ];

    protected $casts = [
        'owner_scope' => AssetOwnerScope::class,
        'opacity'     => 'float',
        'angle_deg'   => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 2: Factory**

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WatermarkTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class WatermarkTemplateFactory extends Factory
{
    protected $model = WatermarkTemplate::class;

    public function definition(): array
    {
        return [
            'owner_scope' => 'personal',
            'owner_id'    => User::factory(),
            'name'        => 'Confidential',
            'type'        => 'text',
            'text'        => 'CONFIDENTIAL',
            'color'       => '#dc2626',
            'opacity'     => 0.18,
            'angle_deg'   => -30,
            'created_by'  => User::factory(),
        ];
    }
}
```

- [ ] **Step 3: Policy** (same scope/delete rules as letterhead — text duplicated intentionally for clarity):

```php
<?php

namespace App\Policies;

use App\Enums\AssetOwnerScope;
use App\Models\User;
use App\Models\WatermarkTemplate;
use Illuminate\Http\Request;

class WatermarkTemplatePolicy
{
    public function viewAny(User $user): bool { return true; }

    public function create(User $user, ?Request $request = null): bool
    {
        $request ??= request();
        $scope = $request?->input('owner_scope');
        $ownerId = $request?->input('owner_id');

        return match ($scope) {
            AssetOwnerScope::Personal->value     => true,
            AssetOwnerScope::Department->value   => $user->employee?->department_id !== null
                                                    && (int) $ownerId === $user->employee->department_id,
            AssetOwnerScope::Organization->value => $user->hasPermission('document_assets.manage'),
            default                              => false,
        };
    }

    public function delete(User $user, WatermarkTemplate $template): bool
    {
        if ($user->hasPermission('document_assets.manage')) return true;
        return $template->created_by === $user->id;
    }
}
```

- [ ] **Step 4: Register** in `AuthServiceProvider::$policies`:

```php
        \App\Models\WatermarkTemplate::class => \App\Policies\WatermarkTemplatePolicy::class,
```

- [ ] **Step 5:** Commit `feat(documents): WatermarkTemplate model + policy`.

---

### Task 5.5: Request, Resource, Service, Controller, Routes

**Files:**
- Create: `app/Http/Requests/DocumentAssets/StoreWatermarkRequest.php`
- Create: `app/Http/Resources/WatermarkTemplateResource.php`
- Create: `app/Services/WatermarkTemplateService.php`
- Create: `app/Http/Controllers/Settings/WatermarkTemplateController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\DocumentAssets;

use App\Enums\AssetOwnerScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreWatermarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\WatermarkTemplate::class) === true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:120'],
            'owner_scope' => ['required', new Enum(AssetOwnerScope::class)],
            'owner_id'    => [Rule::requiredIf(fn () => $this->input('owner_scope') === 'department'), 'nullable', 'integer'],
            'type'        => ['required', 'in:text,image'],
            'text'        => [Rule::requiredIf(fn () => $this->input('type') === 'text'), 'nullable', 'string', 'max:120'],
            'color'       => ['nullable', 'string', 'max:9'],
            'file'        => [Rule::requiredIf(fn () => $this->input('type') === 'image'), 'nullable', 'file', 'mimes:png', 'max:1024'],
            'opacity'     => ['nullable', 'numeric', 'between:0.05,1'],
            'angle_deg'   => ['nullable', 'integer', 'between:-90,90'],
        ];
    }
}
```

- [ ] **Step 2: Resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WatermarkTemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'owner_scope' => $this->owner_scope?->value,
            'type'        => $this->type,
            'text'        => $this->text,
            'color'       => $this->color,
            'opacity'     => $this->opacity,
            'angle_deg'   => $this->angle_deg,
            'preview_url' => $this->type === 'image' ? route('settings.watermarks.preview', $this->id) : null,
        ];
    }
}
```

- [ ] **Step 3: Service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\WatermarkTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WatermarkTemplateService
{
    private const DISK = 'local';

    public function store(array $attrs, ?UploadedFile $file, User $by): WatermarkTemplate
    {
        $path = null;
        if ($attrs['type'] === 'image' && $file) {
            $path = sprintf('assets/watermarks/%s.png', Str::uuid());
            Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));
        }

        return WatermarkTemplate::create([
            'owner_scope'   => $attrs['owner_scope'],
            'owner_id'      => $attrs['owner_scope'] === 'personal' ? $by->id : ($attrs['owner_id'] ?? null),
            'name'          => $attrs['name'],
            'type'          => $attrs['type'],
            'text'          => $attrs['text'] ?? null,
            'color'         => $attrs['color'] ?? '#dc2626',
            'storage_path'  => $path,
            'mime'          => $file?->getClientMimeType(),
            'opacity'       => $attrs['opacity'] ?? 0.18,
            'angle_deg'     => $attrs['angle_deg'] ?? -30,
            'font_size_hint'=> $attrs['font_size_hint'] ?? null,
            'created_by'    => $by->id,
        ]);
    }

    public function delete(WatermarkTemplate $template): void
    {
        if ($template->storage_path) {
            Storage::disk(self::DISK)->delete($template->storage_path);
        }
        $template->delete();
    }
}
```

- [ ] **Step 4: Controller** (mirrors LetterheadTemplateController; replace endpoints)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentAssets\StoreWatermarkRequest;
use App\Http\Resources\WatermarkTemplateResource;
use App\Models\WatermarkTemplate;
use App\Services\WatermarkTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WatermarkTemplateController extends Controller
{
    public function __construct(private readonly WatermarkTemplateService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', WatermarkTemplate::class);
        $user = $request->user();
        $departmentId = $user->employee?->department_id;

        $items = WatermarkTemplate::query()
            ->where(function ($q) use ($user, $departmentId) {
                $q->where(fn ($x) => $x->where('owner_scope', 'personal')->where('owner_id', $user->id))
                  ->orWhere(fn ($x) => $x->where('owner_scope', 'department')->where('owner_id', $departmentId))
                  ->orWhere('owner_scope', 'organization');
            })
            ->latest()
            ->get();

        return Inertia::render('Settings/Watermarks', [
            'templates'    => WatermarkTemplateResource::collection($items),
            'canManageOrg' => $user->hasPermission('document_assets.manage'),
            'departmentId' => $departmentId,
            'activeModule' => 'settings',
        ]);
    }

    public function store(StoreWatermarkRequest $request)
    {
        $tpl = $this->service->store($request->validated(), $request->file('file'), $request->user());
        return back()->with('flash.success', "Watermark \"{$tpl->name}\" created.");
    }

    public function destroy(WatermarkTemplate $template)
    {
        $this->authorize('delete', $template);
        $this->service->delete($template);
        return back()->with('flash.success', 'Watermark removed.');
    }

    public function preview(WatermarkTemplate $template): BinaryFileResponse
    {
        $this->authorize('viewAny', WatermarkTemplate::class);
        abort_unless($template->type === 'image' && $template->storage_path, 404);
        return response()->file(Storage::disk('local')->path($template->storage_path));
    }
}
```

- [ ] **Step 5: Routes**

```php
    Route::prefix('settings/watermarks')->name('settings.watermarks.')->group(function () {
        Route::get('/',                  [\App\Http\Controllers\Settings\WatermarkTemplateController::class, 'index'])->name('index');
        Route::post('/',                 [\App\Http\Controllers\Settings\WatermarkTemplateController::class, 'store'])->name('store');
        Route::get('/{template}/preview',[\App\Http\Controllers\Settings\WatermarkTemplateController::class, 'preview'])->name('preview');
        Route::delete('/{template}',     [\App\Http\Controllers\Settings\WatermarkTemplateController::class, 'destroy'])->name('destroy');
    });
```

- [ ] **Step 6:** Commit `feat(documents): watermark template service + controller + routes`.

---

### Task 5.6: `DocumentRenderService` honors `watermark_id`

**Files:** Modify `app/Services/DocumentRenderService.php`

The current `burn()` accepts a `?array $watermark` for the restricted auto-watermark. After this task, `burn()` ALSO picks up `$version->document->watermark` when present, layering it on top of (or replacing) the auto watermark.

- [ ] **Step 1: Extend `burn()` signature.** Leave `?array $watermark` for backwards compatibility but add template detection at the start:

Before the `foreach ($annotations->where('page', $pageNo) as $a)` block, replace the existing `if ($watermark) { ... }` logic with template-aware code. Specifically, inside the per-page loop:

```php
            // Per-document watermark template overrides / augments the auto restricted watermark.
            $tpl = $doc->watermark; // null when documents.watermark_id IS NULL
            if ($tpl) {
                $this->drawTemplateWatermark($pdf, $tpl, $pageWidth, $pageHeight);
            } elseif ($watermark) {
                $this->drawWatermark($pdf, $watermark, $pageWidth, $pageHeight);
            }
```

When a template is present, we bypass cache because templates are document-scoped (not viewer-scoped): make the cache decision template-aware by adding to the `$useCache` calc at the top of `burn()`:

```php
        $useCache = $watermark === null && $doc->watermark_id === null;
```

- [ ] **Step 2: Add the new draw method** at the bottom of the class:

```php
    private function drawTemplateWatermark(\setasign\Fpdi\Tcpdf\Fpdi $pdf, \App\Models\WatermarkTemplate $tpl, float $pageW, float $pageH): void
    {
        $cx = $pageW / 2;
        $cy = $pageH / 2;

        $pdf->StartTransform();
        $pdf->SetAlpha((float) $tpl->opacity);
        $pdf->Rotate((int) $tpl->angle_deg, $cx, $cy);

        if ($tpl->type === 'image' && $tpl->storage_path) {
            $abs = \Illuminate\Support\Facades\Storage::disk('local')->path($tpl->storage_path);
            // Center the image at half the page width.
            $w = $pageW * 0.6;
            $pdf->Image($abs, $cx - $w / 2, $cy - $w / 2, $w, 0, 'PNG');
        } else {
            $text  = (string) ($tpl->text ?? 'WATERMARK');
            $color = $tpl->color ?? '#dc2626';
            [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
            $pdf->SetTextColor($r, $g, $b);
            $size = $tpl->font_size_hint ?: max(36, min(72, (int) round($pageW / 12)));
            $pdf->SetFont('helvetica', 'B', $size);
            $textWidth = $pdf->GetStringWidth($text);
            $pdf->SetXY($cx - $textWidth / 2, $cy - 12);
            $pdf->Cell($textWidth, 24, $text, 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->SetAlpha(1.0);
        $pdf->StopTransform();
    }
```

- [ ] **Step 3:** Commit `feat(documents): render service honors watermark template`.

---

### Task 5.7: `always` mode also stamps non-burned downloads

**Files:** Modify `app/Http/Controllers/DocumentController.php`

- [ ] **Step 1: Modify `download()`** — extend the `$burned` decision to also flip on for `watermark_mode === 'always'`:

```php
        $alwaysWatermark = $document->watermark_mode?->value === 'always' && $document->watermark_id !== null;
        $burned = $isRestricted || $request->boolean('burned', false) || $alwaysWatermark;
```

- [ ] **Step 2:** Commit `feat(documents): always-watermark mode applies to standard downloads`.

---

### Task 5.8: Update `UpdateDocumentRequest` + `DocumentService` for watermark fields

**Files:**
- Modify: `app/Http/Requests/Documents/UpdateDocumentRequest.php`
- Modify: `app/Services/DocumentService.php`
- Modify: `app/Http/Resources/DocumentResource.php`

- [ ] **Step 1: Request rules** — add:

```php
            'watermark_id'   => ['sometimes', 'nullable', 'integer', 'exists:watermark_templates,id'],
            'watermark_mode' => ['sometimes', 'in:none,on_burn,always'],
```

- [ ] **Step 2: Service** — extend `$allowed`:

```php
        $allowed = ['title', 'description', 'confidentiality', 'tags', 'letterhead_id', 'watermark_id', 'watermark_mode'];
```

- [ ] **Step 3: Resource** — expose:

```php
            'watermark_id'   => $this->watermark_id,
            'watermark_mode' => $this->watermark_mode?->value,
```

- [ ] **Step 4:** Commit `feat(documents): edit endpoint accepts watermark fields`.

---

### Task 5.9: Edit drawer watermark picker (Show.vue)

**Files:** Modify `resources/js/Pages/Documents/Show.vue`

- [ ] **Step 1: Extend `editForm`** — add `watermark_id: null`, `watermark_mode: 'on_burn'`.

- [ ] **Step 2: Fetch watermark templates on mount**, alongside the letterhead fetch from Phase 4:

```js
const watermarks = ref([]);
async function loadWatermarks() {
    const res = await axios.get(route('settings.watermarks.index'), { headers: { 'X-Inertia': 'true', 'X-Inertia-Version': '0', Accept: 'application/json' } });
    watermarks.value = res.data?.props?.templates?.data ?? [];
}
onMounted(loadWatermarks);
```

- [ ] **Step 3: Update `openEdit`** to seed `watermark_id` / `watermark_mode` from `D.value`.

- [ ] **Step 4: Add UI inside the Edit drawer** — two selects:

```vue
<div>
    <label class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant mb-1">Watermark</label>
    <select v-model="editForm.watermark_id" class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
        <option :value="null">None</option>
        <option v-for="w in watermarks" :key="w.id" :value="w.id">{{ w.name }} ({{ w.type }})</option>
    </select>
</div>
<div>
    <label class="block text-[10px] font-black uppercase tracking-widest text-on-surface-variant mb-1">When to apply</label>
    <select v-model="editForm.watermark_mode" class="w-full rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
        <option value="on_burn">On burned PDF only</option>
        <option value="always">Always (including original downloads)</option>
        <option value="none">Never</option>
    </select>
</div>
```

- [ ] **Step 5:** Commit `feat(documents): edit drawer watermark picker`.

---

### Task 5.10: `Settings/Watermarks.vue` admin page

**Files:** Create `resources/js/Pages/Settings/Watermarks.vue`

- [ ] **Step 1: Create** (mirror Letterheads.vue structure; for `type: text` show the text + color; for `type: image` show the preview image):

```vue
<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineOptions({ layout: AuthenticatedLayout });

const props = defineProps({
    templates:    { type: Object, required: true },
    canManageOrg: { type: Boolean, default: false },
    departmentId: { type: Number, default: null },
});

const scope = ref('personal');
const form = useForm({
    name: '', owner_scope: 'personal', owner_id: null,
    type: 'text', text: '', color: '#dc2626', opacity: 0.18, angle_deg: -30, file: null,
});

function submit() {
    form.transform((d) => ({
        ...d,
        owner_id: d.owner_scope === 'department' ? props.departmentId : null,
    })).post(route('settings.watermarks.store'), {
        forceFormData: true, onSuccess: () => form.reset(),
    });
}

function remove(t) {
    if (! confirm(`Remove watermark "${t.name}"?`)) return;
    router.delete(route('settings.watermarks.destroy', t.id), { preserveScroll: true });
}

const SCOPES = ['personal', 'department', 'organization'];
</script>

<template>
    <Head title="Watermarks" />
    <div data-page-root="true">
        <Teleport to="#page-header-mount" defer>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-secondary/80">SETTINGS · WATERMARKS</p>
                <h1 class="text-[1.6rem] font-black tracking-tight text-primary leading-tight">Watermark templates</h1>
                <p class="mt-1 text-[13px] text-on-surface-variant">Text or PNG watermarks applied to burned PDFs.</p>
            </div>
        </Teleport>

        <form @submit.prevent="submit" enctype="multipart/form-data"
              class="rounded-2xl border border-outline-variant/50 bg-surface-container-lowest p-4 shadow-card mb-6 grid md:grid-cols-6 gap-3">
            <input v-model="form.name" required placeholder="Name"
                   class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]" />
            <select v-model="form.owner_scope" class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                <option v-for="s in SCOPES" :key="s" :value="s"
                        :disabled="(s === 'organization' && !canManageOrg) || (s === 'department' && !departmentId)">{{ s }}</option>
            </select>
            <select v-model="form.type" class="rounded-lg border border-outline-variant px-3 py-2 text-[13px]">
                <option value="text">Text</option>
                <option value="image">Image (PNG)</option>
            </select>
            <template v-if="form.type === 'text'">
                <input v-model="form.text" required placeholder="WATERMARK TEXT"
                       class="rounded-lg border border-outline-variant px-3 py-2 text-[13px] font-bold uppercase" />
                <input v-model="form.color" type="color" class="rounded-lg border border-outline-variant w-full h-10" />
            </template>
            <template v-else>
                <input type="file" required accept="image/png"
                       @change="e => form.file = e.target.files[0]" class="text-[12px] md:col-span-2" />
            </template>
            <button type="submit" :disabled="form.processing"
                    class="rounded-lg px-4 py-2 text-[12px] font-black text-white"
                    style="background:linear-gradient(135deg,#0d1452,#1a237e)">
                {{ form.processing ? 'Saving…' : 'Save' }}
            </button>
        </form>

        <div class="flex gap-2 mb-3">
            <button v-for="s in SCOPES" :key="s" @click="scope = s"
                    :class="['rounded-xl px-3 py-1.5 text-[11px] font-black uppercase tracking-widest',
                             scope === s ? 'bg-secondary/10 text-secondary' : 'text-on-surface-variant']">{{ s }}</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div v-for="t in templates.data.filter(x => x.owner_scope === scope)" :key="t.id"
                 class="rounded-xl border border-outline-variant/50 bg-surface-container-lowest p-3 shadow-card">
                <img v-if="t.type === 'image'" :src="t.preview_url" :alt="t.name" class="h-24 w-full object-contain bg-white rounded" />
                <div v-else class="flex items-center justify-center h-24 bg-white rounded">
                    <span :style="{ color: t.color, opacity: t.opacity, transform: `rotate(${t.angle_deg}deg)` }"
                          class="font-black text-[18px] tracking-wider">{{ t.text }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <p class="text-[12px] font-black truncate">{{ t.name }}</p>
                    <button @click="remove(t)" class="text-[11px] font-black text-rose-600">Remove</button>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Add nav link** under Settings.
- [ ] **Step 3:** Commit `feat(documents): watermark admin page`.

---

### Task 5.11: Tests

**Files:**
- Create: `tests/Feature/DocumentAssets/WatermarkTemplateTest.php`
- Create: `tests/Feature/Documents/WatermarkOverrideTest.php`

- [ ] **Step 1: CRUD test**

```php
<?php

use App\Models\User;
use App\Models\WatermarkTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('user can create a text watermark', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('settings.watermarks.store'), [
            'name'        => 'Confidential',
            'owner_scope' => 'personal',
            'type'        => 'text',
            'text'        => 'CONFIDENTIAL',
            'color'       => '#dc2626',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('watermark_templates', [
        'name' => 'Confidential', 'type' => 'text', 'text' => 'CONFIDENTIAL',
    ]);
});

it('user can upload an image watermark', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->image('wm.png', 600, 600)->mimeType('image/png');

    $this->actingAs($user)
        ->post(route('settings.watermarks.store'), [
            'name'        => 'Logo WM',
            'owner_scope' => 'personal',
            'type'        => 'image',
            'file'        => $png,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('watermark_templates', [
        'name' => 'Logo WM', 'type' => 'image',
    ]);
});

it('rejects image watermark above 1 MB', function () {
    $user = User::factory()->create();
    $png  = UploadedFile::fake()->create('wm.png', 1500, 'image/png');
    $this->actingAs($user)
        ->post(route('settings.watermarks.store'), [
            'name' => 'Big', 'owner_scope' => 'personal', 'type' => 'image', 'file' => $png,
        ])
        ->assertSessionHasErrors('file');
});

it('creator can delete their watermark', function () {
    $user = User::factory()->create();
    $tpl  = WatermarkTemplate::factory()->create(['created_by' => $user->id, 'owner_id' => $user->id]);

    $this->actingAs($user)->delete(route('settings.watermarks.destroy', $tpl->id))->assertRedirect();
    $this->assertDatabaseMissing('watermark_templates', ['id' => $tpl->id]);
});
```

- [ ] **Step 2: Override test** (verifies the document's `watermark_id` is applied in `burn`):

```php
<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\WatermarkTemplate;
use App\Services\DocumentRenderService;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('renders the document watermark template into a burned PDF', function () {
    $owner = User::factory()->create();
    $tpl   = WatermarkTemplate::factory()->create([
        'name' => 'DRAFT', 'type' => 'text', 'text' => 'DRAFT WATERMARK',
        'color' => '#205295', 'opacity' => 0.2, 'angle_deg' => -25,
        'created_by' => $owner->id,
    ]);

    $doc = Document::factory()->for($owner, 'owner')->create([
        'watermark_id' => $tpl->id, 'watermark_mode' => 'on_burn',
    ]);

    // Seed a tiny single-page PDF for rendering.
    $samplePdf = file_get_contents(__DIR__ . '/../../Fixtures/sample.pdf');
    Storage::disk('local')->put('documents/test/v1/sample.pdf', $samplePdf);
    $v = DocumentVersion::factory()->for($doc)->create([
        'storage_path' => 'documents/test/v1/sample.pdf',
        'mime' => 'application/pdf',
    ]);
    $doc->update(['current_version_id' => $v->id]);

    $out = app(DocumentRenderService::class)->burn($v->fresh()->load('document.watermark'));
    expect(file_exists($out))->toBeTrue();
    // The renderer cannot be inspected for the text easily without a PDF parser,
    // but we can at least confirm the file size differs from a non-watermarked burn
    // by comparing sizes (template adds ~150 bytes of XObject content).
    expect(filesize($out))->toBeGreaterThan(filesize(Storage::disk('local')->path('documents/test/v1/sample.pdf')));
});

it('always-mode flips burned=true on non-restricted download', function () {
    $owner = User::factory()->create(['permissions' => ['documents.view', 'documents.create']]);
    $tpl   = WatermarkTemplate::factory()->create(['created_by' => $owner->id]);

    Storage::disk('local')->put('documents/abc/v1/orig.pdf', 'PDFBYTES');
    $doc = Document::factory()->for($owner, 'owner')->create([
        'watermark_id'   => $tpl->id,
        'watermark_mode' => 'always',
        'confidentiality'=> 'internal',
    ]);
    $v = DocumentVersion::factory()->for($doc)->create([
        'storage_path' => 'documents/abc/v1/orig.pdf',
        'mime'         => 'application/pdf',
    ]);
    $doc->update(['current_version_id' => $v->id]);

    // Use a signed URL because the route is gated by `signed` middleware.
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'documents.download', now()->addMinutes(5),
        ['document' => $doc->uuid, 'version' => 1],
    );

    $this->actingAs($owner)->get($url)->assertOk();
    $this->assertDatabaseHas('document_events', [
        'document_id' => $doc->id, 'type' => 'downloaded',
    ]);
});
```

- [ ] **Step 3: Prep fixtures.** Ensure `tests/Fixtures/sample.pdf` exists (a one-page valid PDF). If absent, generate one once via:

```bash
php -r '$p = new \TCPDF(); $p->AddPage(); $p->writeHTML("<p>sample</p>"); $p->Output("tests/Fixtures/sample.pdf", "F");'
```

- [ ] **Step 4:** Run `php artisan test --filter=Watermark` — expect PASS.
- [ ] **Step 5:** Commit `test(documents): watermark template + override coverage`.

---

### Task 5.12: Seed the default restricted watermark

**Files:** Modify `database/seeders/DefaultDocumentAssetsSeeder.php`

- [ ] **Step 1:** Append a default text watermark idempotently:

```php
        if (! \App\Models\WatermarkTemplate::where('name', 'RESTRICTED (default)')->exists()) {
            \App\Models\WatermarkTemplate::create([
                'owner_scope' => 'organization',
                'owner_id'    => null,
                'name'        => 'RESTRICTED (default)',
                'type'        => 'text',
                'text'        => 'RESTRICTED',
                'color'       => '#dc2626',
                'opacity'     => 0.18,
                'angle_deg'   => -30,
                'created_by'  => $creator->id,
            ]);
        }
```

- [ ] **Step 2:** Re-run `php artisan db:seed --class=DefaultDocumentAssetsSeeder` — confirm row exists.
- [ ] **Step 3:** Commit `feat(documents): seed default restricted watermark template`.

---

## Final Verification

- [ ] Run the full suite: `cd cihrms-mvp && php artisan test`. Expect all tests green.
- [ ] Run a manual smoke test: upload a stamp, a letterhead, and a watermark. Create a draft document with the composer using the new letterhead. Place a stamp from the library. Drag and rotate it. Attach a watermark. Download burned PDF and confirm watermark visible.
- [ ] Commit any final touch-ups: `chore(documents): v2 wrap-up`.

---

## Self-Review Checklist

- All five planned phases produce working, testable software on their own.
- Phase 1 explicitly marked as already shipped — no duplicate work.
- Migrations are additive; existing documents remain valid.
- Permissions added: `document_assets.manage` (Phase 3); `documents.share_organization` already shipped.
- Restricted auto-watermark behavior preserved as a documented seeded default.
- New `*Policy` classes registered in `AuthServiceProvider`.
- Spec section 7 (Tests) coverage: every numbered test mentioned has a corresponding Task.



