<?php

namespace App\Http\Controllers;

use App\Http\Requests\Establishment\AssignPositionRequest;
use App\Http\Requests\Establishment\StorePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Employee;
use App\Models\Position;
use App\Services\Establishment\PositionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PositionController extends Controller
{
    public function __construct(private readonly PositionService $positions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Position::class);

        $rows = Position::query()
            ->with(['grade', 'department', 'reportsTo'])
            ->when($request->status,        fn ($q, $v) => $q->where('status', $v))
            ->when($request->department_id, fn ($q, $v) => $q->where('department_id', $v))
            ->when($request->grade_id,      fn ($q, $v) => $q->where('grade_id', $v))
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'vacant' => Position::vacant()->count(),
            'filled' => Position::filled()->count(),
            'frozen' => Position::where('status', 'frozen')->count(),
            'total'  => Position::count(),
        ];

        return Inertia::render('Establishment/Positions/Index', [
            'positions'    => PositionResource::collection($rows),
            'stats'        => $stats,
            'filters'      => $request->only(['status', 'department_id', 'grade_id']),
            'activeModule' => 'governance',
        ]);
    }

    public function show(Position $position): Response
    {
        $this->authorize('view', $position);

        $position->load(['grade.steps', 'department', 'reportsTo', 'assignments.employee.user']);

        return Inertia::render('Establishment/Positions/Show', [
            'position'     => new PositionResource($position),
            'assignments'  => $position->assignments->map(fn ($a) => [
                'id'         => $a->id,
                'employee'   => $a->employee?->user?->name,
                'employee_no'=> $a->employee?->employee_no,
                'start_date' => $a->start_date?->toDateString(),
                'end_date'   => $a->end_date?->toDateString(),
                'is_acting'  => (bool) $a->is_acting,
                'reason'     => $a->reason,
            ]),
            'activeModule' => 'governance',
        ]);
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        $this->positions->create($request->validated());
        return back()->with('success', 'Position created.');
    }

    public function assign(AssignPositionRequest $request, Position $position): RedirectResponse
    {
        $this->authorize('assign', $position);

        $employee = Employee::findOrFail($request->validated('employee_id'));

        $this->positions->assign(
            position:  $position,
            employee:  $employee,
            actor:     $request->user(),
            isActing:  (bool) $request->validated('is_acting', false),
            reason:    $request->validated('reason'),
        );

        return back()->with('success', "Employee assigned to position {$position->code}.");
    }

    public function vacate(Request $request, Position $position): RedirectResponse
    {
        $this->authorize('update', $position);
        $reason = (string) $request->input('reason', 'Vacated by HR.');
        $this->positions->vacate($position, $reason);
        return back()->with('success', 'Position vacated.');
    }

    public function freeze(Request $request, Position $position): RedirectResponse
    {
        $this->authorize('freeze', $position);
        $reason = $request->validate(['reason' => 'required|string|max:500'])['reason'];
        $this->positions->freeze($position, $reason);
        return back()->with('success', 'Position frozen.');
    }
}
