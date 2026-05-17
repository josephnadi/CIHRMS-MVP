<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PayrollRunV1Resource;
use App\Models\PayrollRun;
use App\Models\StatutoryReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'year'          => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'status'        => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $runs = PayrollRun::query()
            ->with('department:id,name')
            ->when($request->year,          fn ($q, $v) => $q->where('period_year', $v))
            ->when($request->status,        fn ($q, $v) => $q->where('status', $v))
            ->when($request->department_id, fn ($q, $v) => $q->where('department_id', $v))
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->paginate($request->integer('per_page', 25));

        return PayrollRunV1Resource::collection($runs);
    }

    public function show(PayrollRun $run): PayrollRunV1Resource
    {
        $run->load('department');
        return new PayrollRunV1Resource($run);
    }

    public function returns(PayrollRun $run): JsonResponse
    {
        return response()->json([
            'data' => $run->returns->map(fn ($r) => [
                'id'           => $r->id,
                'kind'         => $r->kind?->value,
                'kind_label'   => $r->kind?->label(),
                'total_amount' => (float) $r->total_amount,
                'record_count' => (int) $r->record_count,
                'generated_at' => optional($r->generated_at)->toIso8601String(),
                'download_url' => route('api.v1.payroll.returns.download', ['run' => $run->id, 'return' => $r->id]),
            ]),
        ]);
    }

    public function downloadReturn(Request $request, PayrollRun $run, int $return): BinaryFileResponse
    {
        $row = StatutoryReturn::where('payroll_run_id', $run->id)->where('id', $return)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($row->file_path), 404);
        return Storage::disk('local')->download($row->file_path);
    }
}
