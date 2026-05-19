<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Atomic, collision-safe generator for `employees.employee_no` and
 * `users.staff_id`.
 *
 * Each call wraps the scan + lock in a transaction with row-level locks
 * (`lockForUpdate`) so two concurrent HR creations can't produce the same
 * identifier. Both target columns already have a UNIQUE index — the lock is
 * defence in depth, the index is the final guarantee.
 *
 * Formats are deliberately backward-compatible with seed data:
 *   - Employee No: `CIHRM-NNNN`  (4-digit min, auto-widens past 9999)
 *   - Staff ID:    `SID-NNNNNN`  (6-digit min)
 */
class EmployeeIdentifierService
{
    private const EMP_PREFIX     = 'CIHRM-';
    private const EMP_MIN_DIGITS = 4;

    private const STAFF_PREFIX     = 'SID-';
    private const STAFF_MIN_DIGITS = 6;

    public function nextEmployeeNo(): string
    {
        return DB::transaction(function () {
            $maxSeq = Employee::withTrashed()
                ->where('employee_no', 'like', self::EMP_PREFIX.'%')
                ->lockForUpdate()
                ->pluck('employee_no')
                ->map(fn (string $no) => $this->parseSequence($no, self::EMP_PREFIX))
                ->max() ?? 0;

            return self::EMP_PREFIX . str_pad(
                (string) ($maxSeq + 1),
                self::EMP_MIN_DIGITS,
                '0',
                STR_PAD_LEFT,
            );
        });
    }

    public function nextStaffId(): string
    {
        return DB::transaction(function () {
            $maxSeq = User::query()
                ->where('staff_id', 'like', self::STAFF_PREFIX.'%')
                ->lockForUpdate()
                ->pluck('staff_id')
                ->map(fn (string $sid) => $this->parseSequence($sid, self::STAFF_PREFIX))
                ->max() ?? 0;

            return self::STAFF_PREFIX . str_pad(
                (string) ($maxSeq + 1),
                self::STAFF_MIN_DIGITS,
                '0',
                STR_PAD_LEFT,
            );
        });
    }

    /**
     * Pulls the trailing digits from "PREFIX-1234" → 1234. Returns 0 when the
     * value doesn't fit the expected shape, which naturally excludes legacy or
     * hand-entered identifiers from the sequence calculation.
     */
    private function parseSequence(string $value, string $prefix): int
    {
        $tail = substr($value, strlen($prefix));
        $digits = preg_replace('/\D/', '', $tail) ?? '';
        return $digits === '' ? 0 : (int) $digits;
    }
}
