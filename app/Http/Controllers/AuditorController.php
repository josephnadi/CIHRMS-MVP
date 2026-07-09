<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\IncomingInvoiceStatus;
use App\Models\IncomingInvoice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditorController extends Controller
{
    public function hub(Request $request): Response
    {
        $counts = IncomingInvoice::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return Inertia::render('Auditor/Hub', [
            'activeModule' => 'auditor',
            'stats' => [
                'pending_vetting' => (int) ($counts[IncomingInvoiceStatus::Submitted->value] ?? 0),
                'pending_ceo'     => (int) ($counts[IncomingInvoiceStatus::Vetted->value] ?? 0),
                'approved'        => (int) ($counts[IncomingInvoiceStatus::Approved->value] ?? 0),
                'returned'        => (int) ($counts[IncomingInvoiceStatus::Returned->value] ?? 0),
            ],
            'links' => [
                'assets'  => $request->user()->hasPermission('assets.view'),
                'reports' => $request->user()->hasPermission('reports.view') || $request->user()->hasPermission('audit.view'),
                'audit'   => $request->user()->hasPermission('audit.view'),
            ],
        ]);
    }
}
