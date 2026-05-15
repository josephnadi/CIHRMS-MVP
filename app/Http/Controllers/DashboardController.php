<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Per-portal access map — the dashboard's `?module=dept-X` query routes the
     * SPA to a department portal section, and we gate it server-side so that
     * direct-URL guessing can't bypass the sidebar.
     */
    private const PORTAL_PERMISSIONS = [
        'dept-it'        => 'portal.it',
        'dept-hr'        => 'portal.hr',
        'dept-marketing' => 'portal.marketing',
        'dept-finance'   => 'portal.finance',
    ];

    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        $user   = $request->user();
        $module = $request->string('module', 'overview')->value();

        if (isset(self::PORTAL_PERMISSIONS[$module])
            && ! $user->hasPermission(self::PORTAL_PERMISSIONS[$module])) {
            abort(403, 'You do not have access to this department portal.');
        }

        return Inertia::render('Dashboard', [
            'activeModule'    => $module,
            'stats'           => $this->dashboard->getStats($user),
            'recentEvents'    => $this->dashboard->getRecentEvents(),
            'employees'       => $this->dashboard->getEmployees(),
            'tickets'         => $this->dashboard->getTickets(),
            'headcountByDept' => $this->dashboard->getHeadcountByDept(),
            'leaveByMonth'    => $this->dashboard->getLeaveByMonth(now()->year),
            'ticketTrend'     => $this->dashboard->getTicketTrend(),
        ]);
    }
}
