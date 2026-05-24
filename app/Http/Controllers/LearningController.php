<?php

namespace App\Http\Controllers;

use App\Http\Requests\Learning\RecordProgressRequest;
use App\Http\Requests\Learning\StoreCertificationRequest;
use App\Http\Requests\Learning\StoreCourseRequest;
use App\Http\Requests\Learning\StoreSkillRequest;
use App\Http\Requests\Learning\UpdateCourseRequest;
use App\Http\Resources\CertificationResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\EnrolmentResource;
use App\Models\Certification;
use App\Models\Course;
use App\Models\Enrolment;
use App\Services\LearningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LearningController extends Controller
{
    public function __construct(private readonly LearningService $learning) {}

    // ── Catalogue ────────────────────────────────────────────────────────

    public function catalog(Request $request): Response
    {
        return Inertia::render('Learning/Catalog', [
            'courses'      => CourseResource::collection($this->learning->catalog($request)),
            'filters'      => $request->only(['search', 'category', 'format']),
            'canManage'    => $request->user()->hasPermission('learning.manage'),
            'activeModule' => 'learning',
        ]);
    }

    public function storeCourse(StoreCourseRequest $request): RedirectResponse
    {
        $this->learning->createCourse($request->validated(), $request->user()->id);
        return back()->with('success', 'Course created.');
    }

    public function updateCourse(UpdateCourseRequest $request, Course $course): RedirectResponse
    {
        $this->learning->updateCourse($course, $request->validated());
        return back()->with('success', 'Course updated.');
    }

    public function publishCourse(Course $course, Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('learning.manage'), 403);
        $this->learning->publishCourse($course);
        return back()->with('success', 'Course published.');
    }

    public function destroyCourse(Course $course, Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('learning.manage'), 403);
        $course->delete();
        return back()->with('success', 'Course removed.');
    }

    // ── My Learning ──────────────────────────────────────────────────────

    public function myLearning(Request $request): Response
    {
        $employee = $request->user()?->employee;

        $enrolments = $employee
            ? $this->learning->myEnrolments($employee)
            : collect();

        $certifications = $employee
            ? Certification::with('course')
                ->where('employee_id', $employee->id)
                ->latest('issued_at')
                ->get()
            : collect();

        $stats = [
            'in_progress' => $enrolments->whereIn('status.value', ['pending', 'active'])->count(),
            'completed'   => $enrolments->where('status.value', 'completed')->count(),
            'certs'       => $certifications->count(),
            'expiring'    => $certifications->filter(fn ($c) => $c->days_to_expiry !== null && $c->days_to_expiry <= 60 && $c->days_to_expiry > 0)->count(),
        ];

        return Inertia::render('Learning/MyLearning', [
            'enrolments'     => EnrolmentResource::collection($enrolments),
            'certifications' => CertificationResource::collection($certifications),
            'stats'          => $stats,
            'activeModule'   => 'learning',
        ]);
    }

    public function enrol(Course $course, Request $request): RedirectResponse
    {
        $employee = $request->user()?->employee;
        abort_unless($employee, 403, 'Only employees can enrol in courses.');
        abort_unless($course->is_published || $request->user()->hasPermission('learning.manage'), 403);

        $this->learning->enrol($course, $employee);
        return back()->with('success', "Enrolled in “{$course->title}”.");
    }

    public function recordProgress(RecordProgressRequest $request, Enrolment $enrolment): RedirectResponse
    {
        // Ownership gate (audit-v2 tier-3 supplement, item 26):
        // route is gated by `learning.view` for self-service, so without this
        // guard any viewer could PATCH another employee's enrolment progress.
        // L&D admins (learning.manage) may update anyone; everyone else only
        // their own enrolment.
        abort_unless(
            $request->user()->hasPermission('learning.manage')
                || $enrolment->employee_id === $request->user()->employee?->id,
            403,
        );

        $this->learning->recordProgress($enrolment, (float) $request->input('progress_pct'));

        if ($request->filled('final_score')) {
            $enrolment->update(['final_score' => $request->input('final_score')]);
        }

        return back()->with('success', 'Progress recorded.');
    }

    // ── Certifications ───────────────────────────────────────────────────

    public function storeCertification(StoreCertificationRequest $request): RedirectResponse
    {
        $this->learning->issueCertification($request->validated(), $request->user()->id);
        return back()->with('success', 'Certification added.');
    }

    // ── Skills matrix ────────────────────────────────────────────────────

    public function skillsMatrix(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('learning.manage'), 403);

        return Inertia::render('Learning/SkillsMatrix', [
            'matrix'       => $this->learning->skillsMatrix($request->integer('department_id') ?: null),
            'activeModule' => 'learning',
        ]);
    }

    public function storeSkill(StoreSkillRequest $request): RedirectResponse
    {
        $skill = $this->learning->createCatalogSkill($request->validated());
        return back()->with('success', "Skill “{$skill->name}” added to the catalogue.");
    }
}
