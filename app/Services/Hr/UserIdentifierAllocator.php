<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Finance\SequenceService;
use RuntimeException;

/**
 * Allocates staff_id / employee_no for admin-created users with silent
 * recovery from stale-preview collisions.
 *
 * The New User form shows a live preview of the next ID. When two admins
 * open the form concurrently, both see the same value — whoever submits
 * second would otherwise hit the UNIQUE constraint as a validation error
 * for something they never typed. The resolve* methods absorb that race
 * by advancing past taken slots, so operators only see "already exists"
 * for values they actually typed.
 *
 * Formats:
 *  - Staff ID:    GH-{DEPT_CODE}-NNNN (per-department counter), or
 *                 GH-NNNN when no department / blank dept code.
 *  - Employee No: CIHRM-NNNN (lifetime-unique, not yearly-scoped).
 */
class UserIdentifierAllocator
{
    /**
     * Upper bound on sequence values to burn while stepping past pre-existing
     * rows. Hitting this means the counter has drifted badly from table data
     * (almost always a seed/import bug, not a real concurrency race) — fail
     * loudly so it surfaces in logs instead of looping forever.
     */
    private const MAX_RESOLUTION_ATTEMPTS = 50;

    public function __construct(private readonly SequenceService $sequences) {}

    public function resolveEmployeeNo(?string $supplied): string
    {
        if ($supplied !== null && $supplied !== '' && ! Employee::where('employee_no', $supplied)->exists()) {
            return $supplied;
        }

        for ($i = 0; $i < self::MAX_RESOLUTION_ATTEMPTS; $i++) {
            $candidate = sprintf('CIHRM-%04d', $this->sequences->next('employee_no'));
            if (! Employee::where('employee_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Failed to allocate a free employee_no after '.self::MAX_RESOLUTION_ATTEMPTS
            .' attempts; sequence counter has drifted from table data.'
        );
    }

    public function resolveStaffId(?string $supplied, ?int $departmentId): string
    {
        if ($supplied !== null && $supplied !== '' && ! User::where('staff_id', $supplied)->exists()) {
            return $supplied;
        }

        [$key, $prefix] = $this->sequenceFor($departmentId);
        for ($i = 0; $i < self::MAX_RESOLUTION_ATTEMPTS; $i++) {
            $candidate = sprintf('%s-%04d', $prefix, $this->sequences->next($key));
            if (! User::where('staff_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Failed to allocate a free staff_id after '.self::MAX_RESOLUTION_ATTEMPTS
            .' attempts; sequence counter has drifted from table data.'
        );
    }

    public function previewEmployeeNo(): string
    {
        return sprintf('CIHRM-%04d', $this->sequences->peek('employee_no'));
    }

    public function previewStaffId(?int $departmentId): string
    {
        [$key, $prefix] = $this->sequenceFor($departmentId);
        return sprintf('%s-%04d', $prefix, $this->sequences->peek($key));
    }

    /** @return array{0:string, 1:string} [sequence_key, prefix] */
    private function sequenceFor(?int $departmentId): array
    {
        if ($departmentId) {
            $code = strtoupper((string) Department::whereKey($departmentId)->value('code'));
            if ($code !== '') {
                return ["staff_id:GH:{$code}", "GH-{$code}"];
            }
        }
        return ['staff_id:GH', 'GH'];
    }
}
