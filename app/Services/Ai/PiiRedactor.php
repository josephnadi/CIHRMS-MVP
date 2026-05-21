<?php

namespace App\Services\Ai;

use App\Models\Employee;

/**
 * Egress firewall for the AI subsystem.
 *
 * Anything an LLM "sees" goes through here first. We pull the blocklist
 * from config/ai.php (see `ai.pii_blocklist`) so the list is auditable
 * and a future DPIA reviewer can answer the question "what about field
 * X?" by pointing at one file.
 *
 * Strategy: ALLOW-list the safe summary view. We don't return an Employee
 * model with some attributes nulled — we project to a fresh array, so a
 * future field added to the schema cannot accidentally leak (it would
 * simply not appear here until we explicitly opt it in).
 */
final class PiiRedactor
{
    /**
     * Return a small, redacted view of the Employee safe to send to an LLM.
     *
     * @return array<string, mixed>
     */
    public function redact(Employee $employee): array
    {
        $department = $employee->relationLoaded('department') ? $employee->department : null;
        $manager    = $employee->relationLoaded('manager')    ? $employee->manager    : null;

        $payload = [
            'employee_no'   => $employee->employee_no,
            'position'      => $employee->position,
            'department'    => $department?->name,
            'status'        => $employee->status instanceof \BackedEnum
                ? $employee->status->value
                : (string) $employee->status,
            'hire_date'     => $employee->hire_date?->format('Y-m'),     // month-precision only
            'tenure_years'  => $employee->tenureYears,
            'gender'        => $employee->gender,                         // demographic, no PII
            'has_manager'   => $manager !== null,
        ];

        // Defence-in-depth: even though we built `$payload` from an allow-list,
        // run it through the blocklist to catch any future drift (e.g. someone
        // adds `phone` here in a hurry without thinking).
        return $this->applyBlocklist($payload);
    }

    /**
     * Drop any key listed in `ai.pii_blocklist` from the given array.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyBlocklist(array $payload): array
    {
        $blocked = array_flip((array) config('ai.pii_blocklist', []));
        return array_diff_key($payload, $blocked);
    }
}
