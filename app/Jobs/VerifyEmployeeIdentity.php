<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Services\Identity\IdentityVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyEmployeeIdentity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $employeeId,
        public readonly string $ghanaCardNumber,
    ) {
        $this->onQueue('identity');
    }

    public function handle(IdentityVerificationService $service): void
    {
        $employee = Employee::find($this->employeeId);
        if (! $employee) return;

        $service->verify($employee, $this->ghanaCardNumber);
    }
}
