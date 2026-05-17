<?php

namespace App\Exports;

use App\Models\Payment;
use App\Support\DbExpr;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PayrollExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly string $month) {}

    public function query()
    {
        return Payment::with(['employee.user'])
            ->whereRaw(DbExpr::yearMonth('created_at') . ' = ?', [$this->month]);
    }

    public function headings(): array
    {
        return ['Employee', 'Description', 'Amount', 'Currency', 'Status', 'Paid At'];
    }

    public function map($payment): array
    {
        return [
            $payment->employee?->user?->name,
            $payment->description,
            number_format((float) $payment->amount, 2),
            $payment->currency,
            $payment->status?->label(),
            $payment->paid_at?->toDateTimeString(),
        ];
    }
}
