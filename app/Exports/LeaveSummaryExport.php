<?php

namespace App\Exports;

use App\Models\LeaveRequest;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class LeaveSummaryExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly int $year) {}

    public function query()
    {
        return LeaveRequest::with(['employee.user', 'approver'])
            ->whereYear('start_date', $this->year)
            ->approved();
    }

    public function headings(): array
    {
        return ['Employee', 'Type', 'Start Date', 'End Date', 'Days', 'Status', 'Approver'];
    }

    public function map($leave): array
    {
        return [
            $leave->employee?->user?->name,
            $leave->type?->label(),
            $leave->start_date?->toDateString(),
            $leave->end_date?->toDateString(),
            $leave->durationInDays(),
            $leave->status?->label(),
            $leave->approver?->name,
        ];
    }
}
