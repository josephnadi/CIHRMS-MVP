<?php

namespace App\Exports;

use App\Models\Employee;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class HeadcountExport implements FromQuery, WithHeadings, WithMapping
{
    public function query()
    {
        return Employee::with(['department', 'user'])->active();
    }

    public function headings(): array
    {
        return ['Name', 'Staff ID', 'Department', 'Position', 'Hire Date', 'Status'];
    }

    public function map($employee): array
    {
        return [
            $employee->user?->name,
            $employee->employee_no,
            $employee->department?->name,
            $employee->position,
            $employee->hire_date?->toDateString(),
            $employee->status?->label(),
        ];
    }
}
