<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreBillingRunRequest;
use App\Models\FeeAssignment;
use App\Models\FeeProduct;
use App\Services\Billing\BillingRunService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BillingRunController extends Controller
{
    public function __construct(private readonly BillingRunService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FeeAssignment::class);

        // Group fee_assignments by (fee_product, period_label) to surface
        // "billing runs" as the operator thinks of them — one row per
        // (product × period), aggregating member + invoice counts.
        $groups = DB::table('fee_assignments')
            ->selectRaw('fee_product_id, period_label,
                COUNT(*) as assignments,
                COUNT(ar_invoice_id) as invoices_minted,
                MIN(created_at) as first_assigned_at,
                MAX(created_at) as last_assigned_at')
            ->groupBy('fee_product_id', 'period_label')
            ->orderByDesc('last_assigned_at')
            ->limit(50)
            ->get();

        $products = FeeProduct::query()->active()->orderBy('code')->get(['id','code','name','amount','currency','billing_cycle']);
        $productIndex = $products->keyBy('id');

        $rows = $groups->map(function ($row) use ($productIndex) {
            $product = $productIndex->get($row->fee_product_id);
            return [
                'fee_product_id'   => $row->fee_product_id,
                'fee_product'      => $product ? [
                    'code'   => $product->code,
                    'name'   => $product->name,
                    'amount' => (float) $product->amount,
                ] : null,
                'period_label'     => $row->period_label,
                'assignments'      => (int) $row->assignments,
                'invoices_minted'  => (int) $row->invoices_minted,
                'first_assigned_at'=> $row->first_assigned_at,
                'last_assigned_at' => $row->last_assigned_at,
            ];
        });

        return Inertia::render('Billing/BillingRuns/Index', [
            'activeModule' => 'billing-runs',
            'runs'         => $rows,
            'products'     => $products,
        ]);
    }

    public function store(StoreBillingRunRequest $request): RedirectResponse
    {
        $product = FeeProduct::findOrFail($request->validated('fee_product_id'));

        try {
            $result = $this->service->run(
                product: $product,
                periodLabel: (string) $request->validated('period_label'),
                operator: $request->user(),
                memberIds: $request->validated('member_ids') ?? null,
                dueDate: $request->filled('due_date')
                    ? CarbonImmutable::parse((string) $request->validated('due_date'))
                    : null,
                invoiceDate: $request->filled('invoice_date')
                    ? CarbonImmutable::parse((string) $request->validated('invoice_date'))
                    : null,
            );
        } catch (DomainException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with([
            'success'      => "Billing run {$result->reference} — minted {$result->invoicesCreated} invoice(s).",
            'billing_run'  => $result->toArray(),
        ]);
    }
}
