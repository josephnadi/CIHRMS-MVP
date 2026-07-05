<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Payroll\StoreSalaryRevisionRequest;
use App\Models\Grade;
use App\Models\SalaryRevision;
use App\Services\Payroll\SalaryRevisionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SalaryRevisionController extends Controller
{
    public function __construct(private readonly SalaryRevisionService $service) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('payroll.run'), 403);

        return Inertia::render('Payroll/Revisions/Index', [
            'revisions'    => SalaryRevision::with('appliedBy:id,name')->latest()->limit(50)->get(),
            'grades'       => Grade::orderBy('code')->get(['id', 'code', 'name']),
            // Current (open) rates so the page can preview old → new client-side.
            'steps'        => \App\Models\GradeStep::query()->whereNull('effective_to')
                ->with('grade:id,code')->orderBy('grade_id')->orderBy('step')
                ->get()->map(fn ($s) => [
                    'grade_id'   => $s->grade_id,
                    'grade_code' => $s->grade?->code,
                    'step'       => $s->step,
                    'base'       => (float) $s->base_salary,
                ]),
            'activeModule' => 'payroll',
        ]);
    }

    /** Live preview of the effect (old → new per grade-step), no persistence. */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('payroll.run'), 403);
        $data = $request->validate([
            'percentage'    => ['required', 'numeric'],
            'effective_from' => ['required', 'date'],
            'overrides'     => ['sometimes', 'array'],
        ]);

        $overrides = [];
        foreach ($request->input('overrides', []) as $o) {
            if (isset($o['grade_id'])) $overrides[(int) $o['grade_id']] = (float) ($o['percentage'] ?? 0);
        }

        return response()->json([
            'rows' => $this->service->preview((float) $data['percentage'], $data['effective_from'], $overrides),
        ]);
    }

    public function store(StoreSalaryRevisionRequest $request): RedirectResponse
    {
        try {
            $rev = $this->service->apply(
                (float) $request->validated('percentage'),
                $request->validated('effective_from'),
                $request->validated('scope'),
                $request->overrideMap(),
                $request->user(),
                $request->validated('notes'),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['effective_from' => $e->getMessage()]);
        }

        return back()->with('success', "Salary revision {$rev->reference} applied to {$rev->affected_count} grade-steps.");
    }
}
