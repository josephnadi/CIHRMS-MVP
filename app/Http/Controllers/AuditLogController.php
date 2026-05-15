<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = AuditLog::with('user:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('path', 'like', "%{$v}%")
                  ->orWhere('action', 'like', "%{$v}%");
            }))
            ->when($request->input('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->latest('id')
            ->paginate(50);

        return Inertia::render('AuditLogs/Index', [
            'logs'         => $logs,
            'filters'      => $request->only(['search', 'user_id']),
            'activeModule' => 'audit-logs',
        ]);
    }
}
