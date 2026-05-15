<?php

namespace App\Exports;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class TurnoverExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return Employee::withTrashed()
            ->with(['department', 'user'])
            ->where(function ($q) {
                $q->where('status', EmployeeStatus::Terminated)
                  ->orWhereNotNull('deleted_at');
            });
    }

    public function headings(): array
    {
        return ['Name', 'Department', 'Hire Date', 'Termination Date', 'Tenure (Days)'];
    }

    public function map($employee): array
    {
        $terminationDate = $employee->deleted_at ?? now();
        $tenure = $employee->hire_date
            ? $employee->hire_date->diffInDays($terminationDate)
            : null;

        return [
            $employee->user?->name,
            $employee->department?->name,
            $employee->hire_date?->toDateString(),
            $terminationDate->toDateString(),
            $tenure,
        ];
    }
}
