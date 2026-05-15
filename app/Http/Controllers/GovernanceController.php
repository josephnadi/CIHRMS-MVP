<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Governance\AcknowledgePolicyRequest;
use App\Http\Requests\Governance\PublishPolicyVersionRequest;
use App\Http\Requests\Governance\StoreCertificationRequest;
use App\Http\Requests\Governance\StorePolicyRequest;
use App\Http\Requests\Governance\StorePolicyVersionRequest;
use App\Http\Requests\Governance\UpdateCertificationRequest;
use App\Http\Requests\Governance\UpdatePolicyRequest;
use App\Http\Resources\GovernanceCertificationResource;
use App\Http\Resources\PolicyResource;
use App\Http\Resources\PolicyVersionResource;
use App\Models\Certification;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Services\GovernanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceController extends Controller
{
    public function __construct(private readonly GovernanceService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Policy::class);

        $policies = Policy::query()
            ->with(['owner:id,name', 'currentVersion'])
            ->orderBy('title')
            ->get();

        return Inertia::render('Governance/Index', [
            'policies'        => PolicyResource::collection($policies),
            'pending_ack_ids' => $this->service->pendingAcksFor($request->user())->pluck('id'),
        ]);
    }

    public function showPolicy(Policy $policy): Response
    {
        $this->authorize('view', $policy);

        $policy->load(['owner:id,name', 'currentVersion', 'versions.publishedBy:id,name']);

        return Inertia::render('Governance/Show', [
            'policy'   => new PolicyResource($policy),
            'versions' => PolicyVersionResource::collection($policy->versions()->orderByDesc('version_number')->get()),
            'current'  => $policy->currentVersion ? new PolicyVersionResource($policy->currentVersion) : null,
        ]);
    }

    public function manage(Request $request): Response
    {
        $this->authorize('manage', Policy::class);

        return Inertia::render('Governance/Manage', [
            'policies' => PolicyResource::collection(
                Policy::with(['owner:id,name', 'currentVersion'])->orderBy('title')->get()
            ),
        ]);
    }

    public function storePolicy(StorePolicyRequest $request)
    {
        $this->service->createPolicy($request->user(), $request->validated());
        return back()->with('success', 'Policy drafted.');
    }

    public function updatePolicy(UpdatePolicyRequest $request, Policy $policy)
    {
        $policy->update($request->validated());
        return back()->with('success', 'Policy updated.');
    }

    public function addVersion(StorePolicyVersionRequest $request, Policy $policy)
    {
        $this->service->addVersion(
            $policy, $request->user(),
            $request->validated('body'),
            $request->validated('changelog'),
        );

        return back()->with('success', 'New version drafted.');
    }

    public function publishVersion(PublishPolicyVersionRequest $request, PolicyVersion $version)
    {
        $this->authorize('manage', $version->policy);

        try {
            $this->service->publish(
                $version, $request->user(),
                new \DateTimeImmutable($request->validated('effective_from')),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Version published.');
    }

    public function acknowledge(AcknowledgePolicyRequest $request, PolicyVersion $version)
    {
        try {
            $this->service->acknowledge(
                $version, $request->user(),
                $request->validated('signed_full_name'),
                $request->ip(),
                (string) $request->userAgent(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Acknowledgement recorded.');
    }

    public function certificationsIndex(Request $request): Response
    {
        $this->authorize('viewAny', Policy::class);
        $canManage = $request->user()->hasPermission('governance.cert_manage');

        $query = Certification::with('employee.user:id,name');

        if (! $canManage) {
            $employeeId = $request->user()->employee?->id;
            $query->where('employee_id', $employeeId ?? 0);
        }

        return Inertia::render('Governance/Certifications', [
            'certifications' => GovernanceCertificationResource::collection($query->latest('expires_at')->paginate(50)),
            'employees'      => $canManage
                ? Employee::with('user:id,name')->active()->orderBy('id')->get(['id', 'user_id', 'employee_no', 'position'])
                : [],
        ]);
    }

    public function storeCertification(StoreCertificationRequest $request)
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));
        $this->service->recordCertification($employee, $request->validated());
        return back()->with('success', 'Certification recorded.');
    }

    public function updateCertification(UpdateCertificationRequest $request, Certification $certification)
    {
        $certification->update($request->validated());
        return back()->with('success', 'Certification updated.');
    }

    public function destroyCertification(Request $request, Certification $certification)
    {
        $this->authorize('manageCertifications', Policy::class);
        $certification->delete();
        return back()->with('success', 'Certification removed.');
    }

    public function dispatchReminders(Request $request)
    {
        $this->authorize('manageCertifications', Policy::class);
        $count = $this->service->dispatchExpiryReminders(30);
        return back()->with('success', "Sent reminders for {$count} expiring certifications.");
    }
}
