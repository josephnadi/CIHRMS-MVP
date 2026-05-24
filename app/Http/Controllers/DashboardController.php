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

        // Role-targeted bundles, computed lazily so we don't bill every role
        // for everyone else's queries. Each is its own cache key inside the
        // service (see DashboardService::STATS_TTL).
        $role = $user->role instanceof \BackedEnum ? $user->role->value : (string) ($user->role ?? '');

        $financeSnapshot = in_array($role, ['finance_officer', 'super_admin', 'ceo', 'hr_admin'], true)
            ? $this->dashboard->getFinanceSnapshot()
            : null;

        $managerSnapshot = in_array($role, ['manager', 'dept_head'], true)
            ? $this->dashboard->getManagerSnapshot($user)
            : null;

        $deptHeadSnapshot = $role === 'dept_head'
            ? $this->dashboard->getDeptHeadSnapshot($user)
            : null;

        return Inertia::render('Dashboard', [
            'activeModule'    => $module,
            'stats'           => $this->dashboard->getStats($user),
            'recentEvents'    => $this->dashboard->getRecentEvents(),
            'employees'       => $this->dashboard->getEmployees(),
            'tickets'         => $this->dashboard->getTickets(),
            'headcountByDept' => $this->dashboard->getHeadcountByDept(),
            'leaveByMonth'    => $this->dashboard->getLeaveByMonth(now()->year),
            'ticketTrend'     => $this->dashboard->getTicketTrend(),
            'sparkSeries'     => [
                'employees'  => $this->dashboard->timeSeries('employees', 30),
                'tickets'    => $this->dashboard->timeSeries('open_tickets', 30),
                'leave'      => $this->dashboard->timeSeries('pending_leave', 30),
                'payroll'    => $this->dashboard->timeSeries('payslips_paid', 30),
                'applicants' => $this->dashboard->timeSeries('applicants', 30),
            ],
            'activityFeed'    => $this->dashboard->getRecentActivityFeed(12),
            'financeSnapshot'  => $financeSnapshot,
            'managerSnapshot'  => $managerSnapshot,
            'deptHeadSnapshot' => $deptHeadSnapshot,
        ]);
    }
}
