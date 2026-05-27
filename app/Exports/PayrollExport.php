<?php

namespace App\Exports;

use App\Models\Payment;
use App\Support\DbExpr;
use App\Support\Spreadsheet;
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
        // M15: escape user-controlled string cells against CSV formula
        // injection. Numeric / date cells are constructed locally and need
        // no escaping. Amount stays a formatted number (callers want it as
        // a string in the spreadsheet, but it won't start with =/+/-/@).
        return [
            Spreadsheet::escapeCell($payment->employee?->user?->name),
            Spreadsheet::escapeCell($payment->description),
            number_format((float) $payment->amount, 2),
            Spreadsheet::escapeCell($payment->currency),
            Spreadsheet::escapeCell($payment->status?->label()),
            $payment->paid_at?->toDateTimeString(),
        ];
    }
}
