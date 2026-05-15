<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssignmentConditionOnReturn;
use App\Enums\MaintenanceType;
use App\Http\Requests\Assets\AssignAssetRequest;
use App\Http\Requests\Assets\CompleteMaintenanceRequest;
use App\Http\Requests\Assets\MarkLostRequest;
use App\Http\Requests\Assets\RetireAssetRequest;
use App\Http\Requests\Assets\ReturnAssetRequest;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Requests\Assets\StoreMaintenanceRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Http\Resources\AssetAssignmentResource;
use App\Http\Resources\AssetMaintenanceResource;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetMaintenance;
use App\Models\Department;
use App\Models\Employee;
use App\Services\AssetService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    public function __construct(private readonly AssetService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Asset::class);

        $query = Asset::query()->with(['currentAssignment.employee.user:id,name']);

        if ($request->filled('category')) $query->where('category', $request->category);
        if ($request->filled('status'))   $query->where('current_status', $request->status);
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($x) use ($q) {
                $x->where('asset_tag', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%")
                  ->orWhere('serial_number', 'like', "%{$q}%");
            });
        }

        $assets = $query->latest()->paginate(20)->withQueryString();

        $stats = [
            'total'       => Asset::count(),
            'assigned'    => Asset::where('current_status', AssetStatus::Assigned->value)->count(),
            'maintenance' => Asset::where('current_status', AssetStatus::Maintenance->value)->count(),
            'in_stock'    => Asset::where('current_status', AssetStatus::InStock->value)->count(),
        ];

        return Inertia::render('Assets/Index', [
            'assets'      => AssetResource::collection($assets),
            'stats'       => $stats,
            'employees'   => Employee::with('user:id,name')->active()->orderBy('id')->get(['id', 'user_id', 'employee_no', 'position', 'department_id']),
            'departments' => Department::orderBy('name')->get(['id', 'name', 'code']),
            'filters'     => $request->only(['category', 'status', 'search']),
        ]);
    }

    public function store(StoreAssetRequest $request)
    {
        $this->service->register($request->validated());
        return back()->with('success', 'Asset registered.');
    }

    public function show(Asset $asset): Response
    {
        $this->authorize('view', $asset);

        return Inertia::render('Assets/Show', [
            'asset'        => (new AssetResource($asset->load('currentAssignment.employee.user')))->resolve(),
            'assignments'  => AssetAssignmentResource::collection(
                $asset->assignments()->with(['employee.user:id,name', 'assignedBy:id,name', 'returnedToUser:id,name'])->latest('assigned_at')->get()
            ),
            'maintenance'  => AssetMaintenanceResource::collection(
                $asset->maintenance()->with('recordedBy:id,name')->latest('started_at')->get()
            ),
            'depreciation' => $asset->depreciationSnapshots()->latest('as_of_date')->limit(12)->get(),
        ]);
    }

    public function update(UpdateAssetRequest $request, Asset $asset)
    {
        $asset->update($request->validated());
        return back()->with('success', 'Asset updated.');
    }

    public function destroy(Asset $asset)
    {
        $this->authorize('manage', $asset);
        $asset->delete();
        return back()->with('success', 'Asset archived.');
    }

    public function myAssets(Request $request): Response
    {
        $employee = $request->user()->employee
            ?? throw new \LogicException('Authenticated user has no employee record.');

        $assignments = AssetAssignment::query()
            ->with(['asset', 'assignedBy:id,name'])
            ->where('employee_id', $employee->id)
            ->whereNull('returned_at')
            ->latest('assigned_at')
            ->get();

        return Inertia::render('Assets/My', [
            'assignments' => AssetAssignmentResource::collection($assignments),
        ]);
    }

    public function assign(AssignAssetRequest $request, Asset $asset)
    {
        $this->authorize('assign', $asset);

        $employee = Employee::findOrFail($request->validated('employee_id'));
        try {
            $this->service->assign(
                $asset, $employee, $request->user(),
                $request->validated('due_back_at') ? new \DateTimeImmutable($request->validated('due_back_at')) : null,
                $request->validated('notes'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Asset assigned.');
    }

    public function returnAsset(ReturnAssetRequest $request, AssetAssignment $assignment)
    {
        $this->authorize('assign', $assignment->asset);

        try {
            $this->service->returnAsset(
                $assignment, $request->user(),
                AssignmentConditionOnReturn::from($request->validated('condition_on_return')),
                $request->validated('notes'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Asset returned.');
    }

    public function storeMaintenance(StoreMaintenanceRequest $request, Asset $asset)
    {
        $this->service->logMaintenance(
            $asset, MaintenanceType::from($request->validated('type')),
            $request->user(), $request->validated(),
        );

        return back()->with('success', 'Maintenance logged.');
    }

    public function completeMaintenance(CompleteMaintenanceRequest $request, AssetMaintenance $maintenance)
    {
        try {
            $this->service->completeMaintenance(
                $maintenance, $request->user(),
                $request->validated('cost') !== null ? (float) $request->validated('cost') : null,
                $request->validated('notes'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Maintenance completed.');
    }

    public function retire(RetireAssetRequest $request, Asset $asset)
    {
        try {
            $this->service->retire($asset, $request->user(), $request->validated('reason'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Asset retired.');
    }

    public function markLost(MarkLostRequest $request, Asset $asset)
    {
        try {
            $this->service->markLost($asset, $request->user(), $request->validated('reason'));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Asset marked lost.');
    }
}
