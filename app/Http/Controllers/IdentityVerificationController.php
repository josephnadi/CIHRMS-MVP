<?php

namespace App\Http\Controllers;

use App\Http\Requests\Identity\VerifyIdentityRequest;
use App\Http\Resources\IdentityVerificationResource;
use App\Models\Employee;
use App\Models\IdentityVerification;
use App\Services\Identity\IdentityVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IdentityVerificationController extends Controller
{
    public function __construct(private readonly IdentityVerificationService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', IdentityVerification::class);

        $rows = IdentityVerification::query()
            ->with('employee.user')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'verified'   => IdentityVerification::where('status', 'verified')->count(),
            'pending'    => IdentityVerification::where('status', 'pending')->count(),
            'failed'     => IdentityVerification::where('status', 'failed')->count(),
            'unverified_employees' => Employee::active()
                ->whereDoesntHave('identityVerifications', fn ($q) => $q->where('status', 'verified'))
                ->count(),
        ];

        return Inertia::render('Identity/Index', [
            'verifications' => IdentityVerificationResource::collection($rows),
            'stats'         => $stats,
            'filters'       => $request->only(['status']),
            'activeModule'  => 'governance',
        ]);
    }

    public function store(VerifyIdentityRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));

        $evidencePath = null;
        if ($request->hasFile('evidence')) {
            $evidencePath = $request->file('evidence')->store('identity_evidence');
        }

        $verification = $this->service->verify(
            employee:         $employee,
            ghanaCardNumber:  (string) $request->validated('ghana_card_number'),
            actor:            $request->user(),
            evidencePath:     $evidencePath,
        );

        $msg = $verification->status->isUsable()
            ? 'Identity verified successfully.'
            : "Verification failed: {$verification->failure_reason}";

        return back()->with($verification->status->isUsable() ? 'success' : 'error', $msg);
    }
}
