<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EmployeeCreated;
use App\Services\Learning\ComplianceAssignmentService;
use Illuminate\Support\Facades\Log;

class AssignComplianceOnHire
{
    public function __construct(private readonly ComplianceAssignmentService $compliance)
    {
    }

    public function handle(EmployeeCreated $event): void
    {
        try {
            $this->compliance->assignForEmployee($event->employee);
        } catch (\Throwable $e) {
            Log::warning('Compliance auto-assignment on hire failed: '.$e->getMessage(), ['employee_id' => $event->employee->id]);
        }
    }
}
