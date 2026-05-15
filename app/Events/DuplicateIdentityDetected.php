<?php

namespace App\Events;

use App\Models\Employee;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when two or more employees share a Ghana Card hash — a leading
 * indicator of a ghost-worker fraud attempt.
 */
class DuplicateIdentityDetected
{
    use Dispatchable, SerializesModels;

    /**
     * @param array<int, Employee> $employees Employees sharing the same Ghana Card hash
     */
    public function __construct(
        public readonly string $cardHash,
        public readonly array $employees,
    ) {}
}
