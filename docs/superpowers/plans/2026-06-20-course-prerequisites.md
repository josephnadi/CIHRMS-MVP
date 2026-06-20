# Course Prerequisites Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add course prerequisites — an employee can't self-enrol in a course until they've completed its prerequisite courses; admin/automated assignment bypasses; the catalog shows locked courses.

**Architecture:** `course_prerequisites` self-referential pivot on `Course`; `Course::prerequisites()` + `unmetPrerequisitesFor(Employee)`; enforcement only in `LearningController::enrol` (the self-enrol path); `LearningService::enrol` untouched (compliance/onboarding auto-enrol bypass).

**Tech Stack:** Laravel 13, Inertia + Vue 3, Pest.

## Global Constraints

- **Do NOT change `LearningService::enrol`** — compliance auto-assign + onboarding auto-enrol call it and must never be blocked by prerequisites. Enforce in the self-enrol controller only.
- Backwards-compatible: a course with no prerequisites behaves exactly as today; the existing learning suite stays green.
- `declare(strict_types=1)`; new inputs carry `aria-label`.

**Spec:** `docs/superpowers/specs/2026-06-20-course-prerequisites-design.md`

---

### Task 1: Pivot + Course relations/helper

**Files:**
- Create: `database/migrations/2026_06_20_300001_create_course_prerequisites.php`
- Modify: `app/Models/Course.php`
- Test: `tests/Feature/Learning/CoursePrerequisiteTest.php`

**Interfaces:**
- Produces: `course_prerequisites` (`course_id`, `prerequisite_course_id`, unique pair); `Course::prerequisites()` belongsToMany (self); `Course::unmetPrerequisitesFor(Employee): \Illuminate\Support\Collection`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;

function prereqCourse(string $title): Course
{
    return Course::create(['title' => $title, 'category' => 'technical', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('relates prerequisites and reports the unmet ones for an employee', function () {
    $basics   = prereqCourse('Basics');
    $advanced = prereqCourse('Advanced');
    $advanced->prerequisites()->attach($basics->id);

    $emp = Employee::factory()->active()->create();

    // Nothing completed yet → Basics is unmet.
    expect($advanced->prerequisites()->pluck('courses.id'))->toContain($basics->id)
        ->and($advanced->unmetPrerequisitesFor($emp)->pluck('id'))->toContain($basics->id);

    // Complete Basics → no unmet prerequisites.
    Enrolment::create(['course_id' => $basics->id, 'employee_id' => $emp->id, 'status' => 'completed', 'enrolled_at' => now(), 'completed_at' => now()]);
    expect($advanced->fresh()->unmetPrerequisitesFor($emp))->toBeEmpty();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Learning/CoursePrerequisiteTest.php`
Expected: FAIL — table/relation/method missing.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_06_20_300001_create_course_prerequisites.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('prerequisite_course_id')->constrained('courses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['course_id', 'prerequisite_course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_prerequisites');
    }
};
```

- [ ] **Step 4: Add the relation + helper to Course**

In `app/Models/Course.php`, add (use `App\Models\Employee`, `App\Enums\EnrolmentStatus`, `Illuminate\Support\Collection`, and the BelongsToMany import or fully-qualify):

```php
    public function prerequisites(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_prerequisites', 'course_id', 'prerequisite_course_id');
    }

    /** Prerequisite courses this employee has NOT completed. */
    public function unmetPrerequisitesFor(Employee $employee): \Illuminate\Support\Collection
    {
        $completed = \App\Models\Enrolment::query()
            ->where('employee_id', $employee->id)
            ->where('status', \App\Enums\EnrolmentStatus::Completed->value)
            ->pluck('course_id');

        return $this->prerequisites()->whereNotIn('courses.id', $completed)->get();
    }
```

- [ ] **Step 5: Run test + commit**

Run: `php artisan test tests/Feature/Learning/CoursePrerequisiteTest.php`
Expected: PASS.

Run: `php artisan migrate:fresh --seed` (clean) and `php artisan test tests/Feature/Learning` (existing green).

```bash
git add database/migrations/2026_06_20_300001_create_course_prerequisites.php app/Models/Course.php tests/Feature/Learning/CoursePrerequisiteTest.php
git commit -m "feat(learning): course prerequisites relation + unmetPrerequisitesFor"
```

---

### Task 2: Self-enrol enforcement + admin authoring

**Files:**
- Modify: `app/Http/Controllers/LearningController.php` (`enrol`)
- Modify: `app/Services/LearningService.php` (`createCourse`/`updateCourse` sync prerequisites)
- Modify: the course store/update FormRequest(s) (find them — `app/Http/Requests/Learning/*`)
- Test: `tests/Feature/Learning/PrerequisiteEnforcementTest.php`

**Interfaces:**
- `LearningController::enrol` blocks when `unmetPrerequisitesFor` is non-empty (no enrolment, error flashed). `createCourse`/`updateCourse` `sync` `prerequisite_ids`. FormRequest validates `prerequisite_ids` (array of existing course ids, none equal to the course itself on update).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrolment;
use App\Models\User;
use App\Services\LearningService;

function pCourse(string $title): Course
{
    return Course::create(['title' => $title, 'category' => 'technical', 'is_published' => true, 'created_by' => User::factory()->create()->id]);
}

it('blocks self-enrol when a prerequisite is incomplete, allows it once completed', function () {
    $basics = pCourse('Basics');
    $advanced = pCourse('Advanced');
    $advanced->prerequisites()->attach($basics->id);

    $user = User::factory()->create(['role' => 'employee']);
    $emp  = Employee::factory()->active()->create(['user_id' => $user->id]);

    // Blocked — no enrolment created.
    $this->actingAs($user)->post(route('learning.courses.enrol', $advanced->id))->assertRedirect();
    expect(Enrolment::where('course_id', $advanced->id)->where('employee_id', $emp->id)->exists())->toBeFalse();

    // Complete the prerequisite → now allowed.
    Enrolment::create(['course_id' => $basics->id, 'employee_id' => $emp->id, 'status' => 'completed', 'enrolled_at' => now(), 'completed_at' => now()]);
    $this->actingAs($user)->post(route('learning.courses.enrol', $advanced->id))->assertRedirect();
    expect(Enrolment::where('course_id', $advanced->id)->where('employee_id', $emp->id)->exists())->toBeTrue();
});

it('does NOT block admin/automated enrol via LearningService::enrol', function () {
    $basics = pCourse('Basics');
    $advanced = pCourse('Advanced');
    $advanced->prerequisites()->attach($basics->id);
    $emp = Employee::factory()->active()->create();

    // Service-level enrol (used by compliance auto-assign + onboarding) bypasses prerequisites.
    app(LearningService::class)->enrol($advanced, $emp);
    expect(Enrolment::where('course_id', $advanced->id)->where('employee_id', $emp->id)->exists())->toBeTrue();
});
```

> Confirm the self-enrol route name (`learning.courses.enrol`) and that `User::factory()->create()->employee` resolves via the `user_id` linkage (the test pins `Employee.user_id`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Learning/PrerequisiteEnforcementTest.php`
Expected: the block test FAILS (currently enrols regardless); the bypass test passes.

- [ ] **Step 3: Enforce in the self-enrol controller**

In `app/Http/Controllers/LearningController.php` `enrol`, before `$this->learning->enrol(...)`:

```php
        $unmet = $course->unmetPrerequisitesFor($employee);
        if ($unmet->isNotEmpty()) {
            return back()->with('error', 'Complete these prerequisite courses first: ' . $unmet->pluck('title')->join(', ') . '.');
        }
```

- [ ] **Step 4: Sync prerequisites in create/update**

In `app/Services/LearningService.php`, in `createCourse` and `updateCourse`, after the course is created/updated, sync prerequisites if provided:

```php
        if (array_key_exists('prerequisite_ids', $data)) {
            $course->prerequisites()->sync(array_values(array_filter((array) $data['prerequisite_ids'])));
        }
```

(Place it before the `return $course->fresh()` / return, and `->load('prerequisites')` if the return is consumed. Don't pass `prerequisite_ids` to `Course::create/update` mass-assignment — it isn't a column; pull it out of `$data` or rely on it not being fillable.)

- [ ] **Step 5: Validate prerequisite_ids in the FormRequest**

Find the course store + update FormRequests (grep `app/Http/Requests/Learning` for the course request used by `courses.store`/`courses.update`). Add:

```php
            'prerequisite_ids'   => ['nullable', 'array'],
            'prerequisite_ids.*' => ['integer', 'exists:courses,id'],
```

For the UPDATE request, also reject self-reference (the course can't be its own prerequisite) — add a rule or a `withValidator` check comparing each id to the route course id. (If quick, mirror an existing `withValidator` in the file; otherwise a closure rule on `prerequisite_ids.*`.)

- [ ] **Step 6: Run test + commit**

Run: `php artisan test tests/Feature/Learning/PrerequisiteEnforcementTest.php`
Expected: PASS (both).

Run: `php artisan test tests/Feature/Learning`
Expected: PASS (existing green).

```bash
git add app/Http/Controllers/LearningController.php app/Services/LearningService.php <course-request(s)> tests/Feature/Learning/PrerequisiteEnforcementTest.php
git commit -m "feat(learning): enforce prerequisites on self-enrol + author them on courses (admin bypasses)"
```

---

### Task 3: Catalog UI + gate

**Files:**
- Modify: `app/Http/Controllers/LearningController.php` (catalog action — pass prerequisites + completedCourseIds) and/or `app/Services/LearningService.php` (`catalog` eager-load `prerequisites`)
- Modify: `resources/js/Pages/Learning/Catalog.vue`
- Test: none new (verification only).

- [ ] **Step 1: Expose prerequisites + completed set to the catalog**

In `LearningService::catalog`, eager-load prerequisites: add `->with('prerequisites:id,title')` to the query (alongside `withCount`). In `LearningController` (the catalog action that renders `Learning/Catalog`), pass the viewer employee's completed course ids:

```php
            'completedCourseIds' => $request->user()?->employee
                ? \App\Models\Enrolment::where('employee_id', $request->user()->employee->id)
                    ->where('status', \App\Enums\EnrolmentStatus::Completed->value)->pluck('course_id')
                : [],
```

(Match how the catalog action currently renders `Learning/Catalog` and add the prop.)

- [ ] **Step 2: Lock courses with unmet prerequisites in Catalog.vue**

In `resources/js/Pages/Learning/Catalog.vue`:
- Add the `completedCourseIds` prop.
- For each course, compute `lockedPrereqs = (course.prerequisites ?? []).filter(p => !completedCourseIds.includes(p.id))`.
- When `lockedPrereqs.length`, show the prerequisite names ("Requires: Basics, …") and **disable** the enrol button (with a tooltip/aria-label explaining it's locked).
- In the course create/edit form, add a **Prerequisites** multi-select of other courses (bound to `prerequisite_ids`, `aria-label="Prerequisite courses"`), submitted with `courses.store`/`courses.update`. Mirror the existing `skill_tags` multi-add pattern in the form.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: succeeds, no Vue errors.

- [ ] **Step 4: Regression gate**

Run: `php artisan test tests/Feature/Learning`
Expected: PASS.

Run: `php artisan test`
Expected: PASS — accessibility green (the prerequisite multi-select carries `aria-label`); allow only the known `KioskRecentTest` flake.

Run: `php artisan migrate:fresh --seed`
Expected: clean.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LearningController.php app/Services/LearningService.php resources/js/Pages/Learning/Catalog.vue
git commit -m "feat(learning): catalog shows prerequisites + locks courses with unmet ones; admin prereq selector"
git commit --allow-empty -m "test(learning): course prerequisites regression gate green"
```

---

## Self-Review notes (for the implementer)

- **Never touch `LearningService::enrol`'s behaviour** — compliance auto-assign + onboarding auto-enrol depend on it not throwing. The bypass test asserts this.
- **Enforcement is self-enrol only**: the block lives in `LearningController::enrol`; admins/automation assign through the service.
- **Completed = met**: a prerequisite is satisfied by any `Completed` enrolment for it.
- **`prerequisite_ids` is not a column** — keep it out of `Course::create/update` mass-assignment; `sync()` the relation separately.
- **Accessibility**: the prerequisite multi-select carries `aria-label`.
