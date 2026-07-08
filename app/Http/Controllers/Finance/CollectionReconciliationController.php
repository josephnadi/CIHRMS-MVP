<?php
declare(strict_types=1);

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ExternalCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CollectionReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('finance.reports'), 403);

        $summary = ExternalCollection::query()
            ->select('fee_code',
                DB::raw("sum(amount) as collected"),
                DB::raw("sum(case when status = 'posted' then amount else 0 end) as posted"),
                DB::raw("sum(case when status <> 'posted' then 1 else 0 end) as unresolved_count"))
            ->groupBy('fee_code')->orderBy('fee_code')->get();

        $unresolved = ExternalCollection::query()
            ->whereIn('status', [ExternalCollection::STATUS_UNMAPPED, ExternalCollection::STATUS_ERROR, ExternalCollection::STATUS_FLAGGED])
            ->latest('paid_at')->limit(200)
            ->get(['id', 'source', 'external_ref', 'fee_code', 'amount', 'status', 'status_note', 'paid_at']);

        return Inertia::render('Finance/CollectionReconciliation/Index', [
            'summary'      => $summary,
            'unresolved'   => $unresolved,
            'activeModule' => 'finance',
        ]);
    }
}
