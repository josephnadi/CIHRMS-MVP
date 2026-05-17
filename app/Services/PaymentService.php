<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Http\Requests\Payment\GeneratePayslipRequest;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Models\Payment;
use App\Models\PayrollItem;
use App\Support\DbExpr;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    public function previewPayslip(array $payload): array
    {
        return $this->calculator->calculate(
            basic:              (float) ($payload['basic'] ?? 0),
            cashAllowances:     $payload['allowances']            ?? [],
            voluntaryDeductions:$payload['voluntary_deductions']  ?? [],
            tier3EmployeeShare: (float) ($payload['tier3_employee'] ?? 0),
        );
    }

    public function generatePayslip(GeneratePayslipRequest $request): Payment
    {
        $data = $request->validated();
        $calc = $this->calculator->calculate(
            basic:              (float) $data['basic'],
            cashAllowances:     $data['allowances']            ?? [],
            voluntaryDeductions:$data['voluntary_deductions']  ?? [],
            tier3EmployeeShare: (float) ($data['tier3_employee'] ?? 0),
        );

        $description = sprintf('Payroll – %s', $data['period']);
        $markPaid    = (bool) ($data['mark_paid'] ?? false);

        return DB::transaction(function () use ($request, $data, $calc, $description, $markPaid) {
            $payment = Payment::create([
                'employee_id'  => $data['employee_id'],
                'processed_by' => $request->user()?->id,
                'description'  => $description,
                'amount'       => $calc['totals']['net_pay'],
                'currency'     => 'GHS',
                'status'       => $markPaid ? PaymentStatus::Paid : PaymentStatus::Pending,
                'paid_at'      => $markPaid ? now() : null,
            ]);

            PayrollItem::create([
                'payment_id' => $payment->id,
                'label'      => 'Basic Salary',
                'type'       => 'earning',
                'amount'     => $calc['earnings']['basic'],
            ]);

            foreach ($data['allowances'] ?? [] as $allowance) {
                if ((float) $allowance['amount'] <= 0) continue;
                PayrollItem::create([
                    'payment_id' => $payment->id,
                    'label'      => $allowance['label'],
                    'type'       => 'earning',
                    'amount'     => round((float) $allowance['amount'], 2),
                ]);
            }

            PayrollItem::create([
                'payment_id' => $payment->id,
                'label'      => 'SSNIT Tier 1 (5.5%)',
                'type'       => 'deduction',
                'amount'     => -$calc['statutory_deductions']['ssnit_tier1_employee'],
            ]);

            PayrollItem::create([
                'payment_id' => $payment->id,
                'label'      => 'PAYE (Income Tax)',
                'type'       => 'deduction',
                'amount'     => -$calc['statutory_deductions']['paye'],
            ]);

            if ((float) ($data['tier3_employee'] ?? 0) > 0) {
                PayrollItem::create([
                    'payment_id' => $payment->id,
                    'label'      => 'SSNIT Tier 3 (voluntary)',
                    'type'       => 'deduction',
                    'amount'     => -round((float) $data['tier3_employee'], 2),
                ]);
            }

            foreach ($data['voluntary_deductions'] ?? [] as $deduction) {
                if ((float) $deduction['amount'] <= 0) continue;
                PayrollItem::create([
                    'payment_id' => $payment->id,
                    'label'      => $deduction['label'],
                    'type'       => 'deduction',
                    'amount'     => -round((float) $deduction['amount'], 2),
                ]);
            }

            return $payment->load('items');
        });
    }

    public function create(StorePaymentRequest $request): Payment
    {
        return Payment::create([
            ...$request->validated(),
            'currency'     => $request->validated('currency', 'GHS'),
            'status'       => PaymentStatus::Pending,
            'processed_by' => $request->user()->id,
        ]);
    }

    public function markPaid(Payment $payment, int $userId): Payment
    {
        $payment->update([
            'status'       => PaymentStatus::Paid,
            'paid_at'      => now(),
            'processed_by' => $userId,
        ]);

        return $payment;
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return Payment::with(['employee.user', 'processedBy'])
            ->when($request->status,      fn ($q, $v) => $q->where('status', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->month, function ($q, $v) {
                [$year, $month] = explode('-', $v);
                $q->whereYear('created_at', (int) $year)
                  ->whereMonth('created_at', (int) $month);
            })
            ->latest()
            ->paginate($request->per_page ?? 20)
            ->withQueryString();
    }

    public function createPayslip(Payment $payment, array $items): Payment
    {
        foreach ($items as $item) {
            PayrollItem::create([
                'payment_id' => $payment->id,
                'label'      => $item['label'],
                'type'       => $item['type'],
                'amount'     => $item['amount'],
            ]);
        }

        return $payment->load('items');
    }

    public function analytics(): array
    {
        return Cache::remember('payroll_analytics', 60, fn () => [
            'totals'           => $this->totals(),
            'volumeByMonth'    => $this->volumeByMonth(),
            'statusBreakdown'  => $this->statusBreakdown(),
            'currencySplit'    => $this->currencySplit(),
            'topEarners'       => $this->topEarners(),
            'earningsVsDeductions' => $this->earningsVsDeductions(),
        ]);
    }

    private function totals(): array
    {
        $thisMonthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonth()->startOfMonth();
        $lastMonthEnd   = now()->subMonth()->endOfMonth();

        $totalPaid = (float) Payment::where('status', PaymentStatus::Paid->value)->sum('amount');
        $totalPending = (float) Payment::where('status', PaymentStatus::Pending->value)->sum('amount');

        $thisMonth = (float) Payment::where('status', PaymentStatus::Paid->value)
            ->where('paid_at', '>=', $thisMonthStart)
            ->sum('amount');

        $lastMonth = (float) Payment::where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $delta = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1) : 0;

        return [
            'total_paid'      => $totalPaid,
            'total_pending'   => $totalPending,
            'this_month'      => $thisMonth,
            'last_month'      => $lastMonth,
            'delta_pct'       => $delta,
            'paid_count'      => Payment::where('status', PaymentStatus::Paid->value)->count(),
            'pending_count'   => Payment::where('status', PaymentStatus::Pending->value)->count(),
        ];
    }

    private function volumeByMonth(): array
    {
        $start = now()->subMonths(11)->startOfMonth();

        $paid = Payment::selectRaw(DbExpr::yearMonth('paid_at') . ' as period, SUM(amount) as total')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->where('status', PaymentStatus::Paid->value)
            ->groupBy('period')
            ->pluck('total', 'period')
            ->toArray();

        $result = [];
        $cursor = CarbonImmutable::instance($start);
        for ($i = 0; $i < 12; $i++) {
            $period = $cursor->addMonths($i);
            $key    = $period->format('Y-m');
            $result[] = [
                'label' => $period->format('M'),
                'period'=> $key,
                'value' => round((float) ($paid[$key] ?? 0), 2),
            ];
        }
        return $result;
    }

    private function statusBreakdown(): array
    {
        return Payment::selectRaw('status, COUNT(*) as total, SUM(amount) as amount')
            ->groupBy('status')
            ->get()
            ->map(function ($r) {
                $raw = \is_object($r->status) ? $r->status->value : (string) $r->status;
                return [
                    'label'  => ucfirst($raw),
                    'value'  => (int) $r->total,
                    'amount' => round((float) $r->amount, 2),
                ];
            })
            ->toArray();
    }

    private function currencySplit(): array
    {
        return Payment::selectRaw('currency, SUM(amount) as total, COUNT(*) as count')
            ->where('status', PaymentStatus::Paid->value)
            ->groupBy('currency')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'label'  => $r->currency ?: 'GHS',
                'value'  => round((float) $r->total, 2),
                'count'  => (int) $r->count,
            ])
            ->toArray();
    }

    private function topEarners(): array
    {
        return Payment::selectRaw('employee_id, SUM(amount) as total, COUNT(*) as count')
            ->with(['employee.user:id,name', 'employee.department:id,name'])
            ->where('status', PaymentStatus::Paid->value)
            ->where('paid_at', '>=', now()->subMonths(6))
            ->groupBy('employee_id')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->employee_id,
                'name'       => $r->employee?->user?->name ?? '—',
                'department' => $r->employee?->department?->name,
                'employee_no'=> $r->employee?->employee_no,
                'total'      => round((float) $r->total, 2),
                'count'      => (int) $r->count,
            ])
            ->toArray();
    }

    private function earningsVsDeductions(): array
    {
        $earnings   = (float) PayrollItem::where('type', 'earning')->sum('amount');
        $deductions = (float) PayrollItem::where('type', 'deduction')->sum('amount');

        return [
            'earnings'   => round($earnings, 2),
            'deductions' => round(abs($deductions), 2),
            'net'        => round($earnings - abs($deductions), 2),
        ];
    }
}
