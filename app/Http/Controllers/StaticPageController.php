<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders sidebar pages whose content is currently a styled skeleton — no
 * dedicated backend service yet. Each method just hands an `activeModule`
 * (and, for departments, a slug + optional Department record) to the Vue page.
 *
 * When real backends land for any of these modules, the matching method here
 * should be deleted and the route re-pointed at a proper controller.
 */
class StaticPageController extends Controller
{
    public function attendance(): Response
    {
        return Inertia::render('Attendance/Index', [
            'activeModule' => 'attendance',
        ]);
    }

    public function governance(): Response
    {
        return Inertia::render('Governance/Index', [
            'activeModule' => 'governance',
        ]);
    }

    public function assets(): Response
    {
        return Inertia::render('Assets/Index', [
            'activeModule' => 'assets',
        ]);
    }

    public function benefits(): Response
    {
        return Inertia::render('Benefits/Index', [
            'activeModule' => 'benefits',
        ]);
    }

    public function department(Request $request, string $slug): Response
    {
        // Map sidebar slug → a Department code so we can show real members later.
        $codeMap = [
            'it'             => 'IT',
            'hr'             => 'HR',
            'marketing'      => 'MKT',
            'finance'        => 'FIN',
            'membership'     => 'MEM',
            'pcp'            => 'PCP',
            'cpd'            => 'CPD',
            'administration' => 'ADM',
        ];
        $code = $codeMap[$slug] ?? null;

        $department = $code
            ? Department::with('head:id,name')->where('code', $code)->first()
            : null;

        // Real roster + headcount for the department (the KPI/section fabrications
        // the page used to show are replaced by these live figures).
        $members = collect();
        $headcount = 0;
        if ($department) {
            $employees = \App\Models\Employee::query()
                ->where('department_id', $department->id)
                ->where('status', 'active')
                ->with('user:id,name')
                ->orderBy('position')
                ->get(['id', 'user_id', 'position', 'employee_no', 'department_id']);
            $headcount = $employees->count();
            $members = $employees->take(24)->map(fn ($e) => [
                'name'     => $e->user?->name ?? $e->employee_no,
                'position' => $e->position,
            ])->values();
        }

        return Inertia::render('Departments/Show', [
            'slug'         => $slug,
            'department'   => $department ? [
                'id'   => $department->id,
                'name' => $department->name,
                'code' => $department->code,
                'head' => $department->head ? ['id' => $department->head->id, 'name' => $department->head->name] : null,
            ] : null,
            'headcount'    => $headcount,
            'members'      => $members,
            'activeModule' => 'dept-' . $slug,
        ]);
    }
}
