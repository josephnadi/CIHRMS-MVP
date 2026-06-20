<?php

declare(strict_types=1);

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\StoreComplianceRequirementRequest;
use App\Models\ComplianceRequirement;
use App\Models\Course;
use App\Models\Enrolment;
use App\Services\Learning\ComplianceAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceController extends Controller
{
    public function __construct(private readonly ComplianceAssignmentService $compliance)
    {
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('learning.compliance.manage'), 403);

        $requirements = ComplianceRequirement::with('course')->latest()->get()->map(function ($r) {
            $enrolments = $r->enrolments();

            return [
                'id' => $r->id, 'name' => $r->name, 'course' => $r->course?->title,
                'target' => $r->target_type->label(), 'due_in_days' => $r->due_in_days, 'is_active' => $r->is_active,
                'assigned'  => (clone $enrolments)->count(),
                'completed' => (clone $enrolments)->where('status', 'completed')->count(),
                'overdue'   => $r->enrolments()->overdue()->count(),
            ];
        });

        $overduePeople = Enrolment::overdue()->with(['employee.user', 'course'])->get()->map(fn ($e) => [
            'employee' => $e->employee?->user?->name, 'course' => $e->course?->title, 'due_at' => optional($e->due_at)->toDateString(),
        ]);

        return Inertia::render('Learning/Compliance', [
            'requirements'  => $requirements,
            'overduePeople' => $overduePeople,
            'courses'       => Course::published()->get(['id', 'title']),
            'activeModule'  => 'learning',
        ]);
    }

    public function store(StoreComplianceRequirementRequest $request): RedirectResponse
    {
        $req = ComplianceRequirement::create([
            ...$request->validated(),
            'is_active' => true,
            'target_value' => $request->input('target_type') === 'all_staff' ? null : $request->input('target_value'),
        ]);

        $assigned = $this->compliance->syncRequirement($req);

        return back()->with('success', "Requirement created; {$assigned} employee(s) assigned.");
    }
}
