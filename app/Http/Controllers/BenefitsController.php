<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ClaimStatus;
use App\Http\Requests\Benefits\DecideClaimRequest;
use App\Http\Requests\Benefits\EnrolRequest;
use App\Http\Requests\Benefits\StoreDependantRequest;
use App\Http\Requests\Benefits\StorePlanRequest;
use App\Http\Requests\Benefits\SubmitClaimRequest;
use App\Http\Requests\Benefits\UpdateDependantRequest;
use App\Http\Requests\Benefits\UpdatePlanRequest;
use App\Http\Resources\BenefitClaimResource;
use App\Http\Resources\BenefitEnrolmentResource;
use App\Http\Resources\BenefitPlanResource;
use App\Http\Resources\DependantResource;
use App\Models\BenefitClaim;
use App\Models\BenefitEnrolment;
use App\Models\BenefitPlan;
use App\Models\Dependant;
use App\Services\BenefitsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BenefitsController extends Controller
{
    public function __construct(private readonly BenefitsService $service) {}

    public function index(Request $request): Response
    {
        $employee = $request->user()->employee;

        $data = [
            'enrolments'  => [],
            'dependants'  => [],
            'claims'      => [],
            'plans'       => BenefitPlanResource::collection(BenefitPlan::active()->orderBy('name')->get()),
            'provident'   => [],
        ];

        if ($employee) {
            $data['enrolments'] = BenefitEnrolmentResource::collection(
                $employee->benefitEnrolments()->with('plan')->latest()->get()
            );
            $data['dependants'] = DependantResource::collection($employee->dependants()->latest()->get());
            $data['claims']     = BenefitClaimResource::collection(
                BenefitClaim::query()
                    ->whereIn('enrolment_id', $employee->benefitEnrolments()->pluck('id'))
                    ->with(['enrolment.plan', 'enrolment.employee.user', 'decidedBy:id,name'])
                    ->latest('submitted_at')
                    ->limit(50)
                    ->get()
            );
            $data['provident'] = $this->service->providentFundView($employee);
        }

        return Inertia::render('Benefits/Index', $data);
    }

    public function plansIndex(): Response
    {
        $this->authorize('managePlans', BenefitPlan::class);

        return Inertia::render('Benefits/Plans', [
            'plans' => BenefitPlanResource::collection(BenefitPlan::orderBy('name')->paginate(50)),
        ]);
    }

    public function storePlan(StorePlanRequest $request)
    {
        $this->service->createPlan($request->validated(), $request->user());
        return back()->with('success', 'Plan created.');
    }

    public function updatePlan(UpdatePlanRequest $request, BenefitPlan $plan)
    {
        $plan->update($request->validated());
        return back()->with('success', 'Plan updated.');
    }

    public function destroyPlan(BenefitPlan $plan)
    {
        $this->authorize('managePlans', BenefitPlan::class);
        $plan->delete();
        return back()->with('success', 'Plan archived.');
    }

    public function enrol(EnrolRequest $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404, 'No employee record linked to this user.');

        $plan = BenefitPlan::findOrFail($request->validated('plan_id'));

        try {
            $this->service->enrol(
                $plan, $employee,
                new \DateTimeImmutable($request->validated('effective_from')),
                $request->validated('premium') !== null ? (float) $request->validated('premium') : null,
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Enroled successfully.');
    }

    public function storeDependant(StoreDependantRequest $request)
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 404, 'No employee record linked to this user.');

        try {
            $this->service->addDependant($employee, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dependant added.');
    }

    public function updateDependant(UpdateDependantRequest $request, Dependant $dependant)
    {
        abort_unless($dependant->employee_id === $request->user()->employee?->id || $request->user()->hasPermission('benefits.view_all'), 403);
        $dependant->update($request->validated());
        return back()->with('success', 'Dependant updated.');
    }

    public function destroyDependant(Request $request, Dependant $dependant)
    {
        abort_unless($dependant->employee_id === $request->user()->employee?->id || $request->user()->hasPermission('benefits.view_all'), 403);
        $dependant->delete();
        return back()->with('success', 'Dependant removed.');
    }

    public function claimsIndex(): Response
    {
        $this->authorize('manageClaims', BenefitClaim::class);

        return Inertia::render('Benefits/Claims', [
            'claims' => BenefitClaimResource::collection(
                BenefitClaim::with(['enrolment.plan', 'enrolment.employee.user', 'decidedBy:id,name'])
                    ->latest('submitted_at')
                    ->paginate(20)
            ),
        ]);
    }

    public function submitClaim(SubmitClaimRequest $request)
    {
        $enrolment = BenefitEnrolment::findOrFail($request->validated('enrolment_id'));
        $this->authorize('submitClaim', $enrolment);

        try {
            $this->service->submitClaim($enrolment, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Claim submitted.');
    }

    public function decideClaim(DecideClaimRequest $request, BenefitClaim $claim)
    {
        try {
            $this->service->decideClaim(
                $claim,
                ClaimStatus::from($request->validated('status')),
                $request->user(),
                $request->validated('notes'),
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Claim decision recorded.');
    }

    public function withdrawClaim(Request $request, BenefitClaim $claim)
    {
        // Only the claimant (owner of the enrolment) may withdraw their own claim.
        $employee = $request->user()->employee;
        $claim->loadMissing('enrolment');
        abort_unless($employee && $claim->enrolment?->employee_id === $employee->id, 403);

        try {
            $this->service->withdrawClaim($claim, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Claim withdrawn.');
    }

    public function downloadECard(Request $request, BenefitEnrolment $enrolment)
    {
        $this->authorize('viewEnrolment', $enrolment);

        $enrolment->load(['plan', 'employee.user', 'employee.dependants']);

        $pdf = Pdf::loadView('pdf.benefits-ecard', [
            'enrolment'  => $enrolment,
            'plan'       => $enrolment->plan,
            'employee'   => $enrolment->employee,
            'dependants' => $enrolment->employee?->dependants ?? collect(),
        ]);

        return $pdf->download("ecard-{$enrolment->id}.pdf");
    }
}
