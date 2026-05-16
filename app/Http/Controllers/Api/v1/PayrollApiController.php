<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollRunResource;
use App\Models\PayrollRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollApiController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasPermission('payroll.view_all'), 403);

        return PayrollRunResource::collection(
            PayrollRun::with(['department', 'approver'])
                ->when($request->year,   fn ($q, $v) => $q->where('period_year', $v))
                ->when($request->status, fn ($q, $v) => $q->where('status', $v))
                ->latest('period_year')->latest('period_month')
                ->paginate((int) min($request->per_page ?? 25, 100)),
        );
    }

    public function show(Request $request, PayrollRun $run): PayrollRunResource
    {
        abort_unless($request->user()->hasPermission('payroll.view_all'), 403);
        return new PayrollRunResource($run->load(['department', 'creator', 'approver']));
    }
}
