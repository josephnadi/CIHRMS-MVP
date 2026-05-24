<?php

namespace App\Services;

use App\Enums\EnrolmentStatus;
use App\Events\CertificationIssued;
use App\Events\CourseCompleted;
use App\Events\CourseEnrolled;
use App\Models\Certification;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\Enrolment;
use App\Models\SkillCatalogItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningService
{
    // ─────────────────────────────────────────────────────────────────────
    // Catalogue
    // ─────────────────────────────────────────────────────────────────────

    public function catalog(Request $request): LengthAwarePaginator
    {
        return Course::query()
            ->when(! $request->user()?->hasPermission('learning.manage'), fn ($q) => $q->published())
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->when($request->format,   fn ($q, $v) => $q->where('format', $v))
            ->when($request->search,   fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('title', 'like', "%{$v}%")
                  ->orWhere('description', 'like', "%{$v}%")
                  ->orWhere('provider', 'like', "%{$v}%");
            }))
            ->withCount(['enrolments as enrolled_count'])
            ->latest('published_at')
            ->paginate($request->per_page ?? 12)
            ->withQueryString();
    }

    public function findCourse(int|string $idOrSlug): Course
    {
        return Course::with('enrolments.employee.user')
            ->where(is_numeric($idOrSlug) ? 'id' : 'slug', $idOrSlug)
            ->firstOrFail();
    }

    public function createCourse(array $data, ?int $createdBy = null): Course
    {
        return Course::create([
            ...$data,
            'created_by' => $createdBy,
        ])->fresh();
    }

    public function updateCourse(Course $course, array $data): Course
    {
        $course->update($data);
        return $course->fresh();
    }

    public function publishCourse(Course $course): Course
    {
        $course->update([
            'is_published' => true,
            'published_at' => $course->published_at ?? now(),
        ]);
        return $course;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Enrolments
    // ─────────────────────────────────────────────────────────────────────

    public function listEnrolments(Request $request): LengthAwarePaginator
    {
        return Enrolment::with(['course', 'employee.user'])
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', (int) $v))
            ->when($request->course_id,   fn ($q, $v) => $q->where('course_id', (int) $v))
            ->when($request->status,      fn ($q, $v) => $q->where('status', $v))
            ->latest('enrolled_at')
            ->paginate($request->per_page ?? 20)
            ->withQueryString();
    }

    public function myEnrolments(Employee $employee): Collection
    {
        return Enrolment::with('course')
            ->where('employee_id', $employee->id)
            ->latest('enrolled_at')
            ->get();
    }

    public function enrol(Course $course, Employee $employee): Enrolment
    {
        $enrolment = Enrolment::firstOrCreate(
            ['course_id' => $course->id, 'employee_id' => $employee->id],
            [
                'status'      => EnrolmentStatus::Pending->value,
                'enrolled_at' => now(),
            ],
        );

        if ($enrolment->wasRecentlyCreated) {
            event(new CourseEnrolled($enrolment));
        }
        return $enrolment->fresh(['course', 'employee.user']);
    }

    public function recordProgress(Enrolment $enrolment, float $pct): Enrolment
    {
        $pct = max(0.0, min(100.0, $pct));

        $update = [
            'progress_pct'     => $pct,
            'last_activity_at' => now(),
        ];

        if ($enrolment->status === EnrolmentStatus::Pending && $pct > 0) {
            $update['status']     = EnrolmentStatus::Active;
            $update['started_at'] = $enrolment->started_at ?? now();
        }

        // Wrap progress-bump and conditional completion in a single transaction
        // so that a failure inside completeEnrolment (which has its own inner
        // transaction → savepoint) rolls the progress update back too. This
        // prevents the row from drifting to 100% with status still "Active".
        return DB::transaction(function () use ($enrolment, $pct, $update) {
            $enrolment->update($update);

            if ($pct >= 100 && $enrolment->status !== EnrolmentStatus::Completed) {
                $this->completeEnrolment($enrolment);
            }

            return $enrolment->fresh(['course']);
        });
    }

    public function completeEnrolment(Enrolment $enrolment, ?float $finalScore = null): Enrolment
    {
        return DB::transaction(function () use ($enrolment, $finalScore) {
            $enrolment->update([
                'status'       => EnrolmentStatus::Completed,
                'progress_pct' => 100,
                'final_score'  => $finalScore ?? $enrolment->final_score,
                'completed_at' => now(),
            ]);

            event(new CourseCompleted($enrolment->fresh(['course', 'employee.user'])));

            return $enrolment->fresh(['course']);
        });
    }

    /** Add the course's skill_tags to the employee's skill record (idempotent). */
    public function grantSkillsFromCourse(Enrolment $enrolment): int
    {
        $tags = (array) ($enrolment->course?->skill_tags ?? []);
        if (! $tags || ! $enrolment->employee_id) return 0;

        $added = 0;
        foreach ($tags as $tag) {
            $name = trim((string) $tag);
            if ($name === '') continue;
            $skill = EmployeeSkill::firstOrCreate(
                ['employee_id' => $enrolment->employee_id, 'name' => $name],
                ['level' => 'intermediate'],
            );
            if ($skill->wasRecentlyCreated) $added++;
        }

        return $added;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Certifications
    // ─────────────────────────────────────────────────────────────────────

    public function listCertifications(Request $request): LengthAwarePaginator
    {
        return Certification::with(['employee.user', 'course'])
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', (int) $v))
            ->when($request->expiring,    fn ($q, $v) => $q->expiringWithin((int) $v))
            ->latest('issued_at')
            ->paginate($request->per_page ?? 20)
            ->withQueryString();
    }

    public function issueCertification(array $data, ?int $actorUserId = null): Certification
    {
        $cert = Certification::create($data);
        event(new CertificationIssued($cert, $actorUserId ? \App\Models\User::find($actorUserId) : null));
        return $cert->fresh(['employee.user', 'course']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Skills matrix — employee × skill pivot for the SkillsMatrix.vue page
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Returns:
     *   employees: [{id, name, position, department, skill_count}]
     *   skills:    [{name, count}]              (sorted by frequency)
     *   matrix:    { "<employee_id>": { "<skill>": "level", ... } }
     */
    public function skillsMatrix(?int $departmentId = null): array
    {
        $employees = Employee::query()
            ->with('user:id,name', 'department:id,name', 'skills')
            ->when($departmentId, fn ($q, $v) => $q->where('department_id', $v))
            ->whereHas('user')
            ->get();

        $skillTotals = [];
        $matrix = [];
        $rows = [];

        foreach ($employees as $emp) {
            $skills = $emp->skills;
            $rows[] = [
                'id'          => $emp->id,
                'name'        => $emp->user?->name,
                'position'    => $emp->position,
                'department'  => $emp->department?->name,
                'skill_count' => $skills->count(),
            ];

            $matrix[$emp->id] = [];
            foreach ($skills as $skill) {
                $matrix[$emp->id][$skill->name] = $skill->level ?? 'beginner';
                $skillTotals[$skill->name] = ($skillTotals[$skill->name] ?? 0) + 1;
            }
        }

        // Catalog skills appear as columns with count 0 when no employee has them yet.
        foreach (SkillCatalogItem::query()->pluck('name') as $name) {
            if (! array_key_exists($name, $skillTotals)) {
                $skillTotals[$name] = 0;
            }
        }

        arsort($skillTotals);
        $skills = [];
        foreach ($skillTotals as $name => $count) {
            $skills[] = ['name' => $name, 'count' => $count];
        }

        return [
            'employees' => $rows,
            'skills'    => $skills,
            'matrix'    => $matrix,
        ];
    }

    public function createCatalogSkill(array $data): SkillCatalogItem
    {
        return SkillCatalogItem::create([
            'name'        => $data['name'],
            'category'    => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
    }
}
