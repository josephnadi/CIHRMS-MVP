<?php

declare(strict_types=1);

namespace App\Http\Controllers\Learning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Learning\StoreComplianceRequirementRequest;
use App\Models\ComplianceRequirement;
use App\Services\Learning\ComplianceAssignmentService;
use Illuminate\Http\RedirectResponse;

class ComplianceController extends Controller
{
    public function __construct(private readonly ComplianceAssignmentService $compliance)
    {
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
