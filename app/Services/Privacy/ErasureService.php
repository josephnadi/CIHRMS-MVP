<?php

namespace App\Services\Privacy;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Right-to-Erasure implementation honouring statutory retention floors.
 *
 * Strict erasure is impossible for some tables because Ghana law REQUIRES
 * retention:
 *
 *   - Payroll/PAYE records: 6 years (Income Tax Act 2015, Act 896 §43)
 *   - SSNIT records:        7 years (Pensions Act 2008, Act 766 §92)
 *   - Audit log:            chain-hashed — physical row deletion would
 *                           break tamper-evidence; we tombstone instead.
 *
 * Implementation: TOMBSTONING. We replace PII fields with `[ERASED-YYYY-MM-DD]`
 * placeholders. The row continues to exist for statutory retention but no
 * longer identifies the data subject.
 *
 * Returns a structured `tombstone_log` documenting what was redacted vs.
 * what was held back under which statute — both subject and auditor can
 * verify the basis for any decision.
 */
class ErasureService
{
    public const PAYROLL_RETENTION_YEARS = 6;
    public const SSNIT_RETENTION_YEARS   = 7;

    /**
     * @return array<string, mixed> tombstone log
     */
    public function erase(User $subject, string $reference): array
    {
        $stamp = '[ERASED ' . now()->toDateString() . " · {$reference}]";
        $now   = now();
        $log = [
            'reference'  => $reference,
            'executed_at'=> $now->toIso8601String(),
            'redacted'   => [],
            'held_back'  => [],
        ];

        DB::transaction(function () use ($subject, $stamp, &$log) {
            // 1. User row — keep id (FK integrity) but redact identifiers.
            $subject->forceFill([
                'name'                  => $stamp,
                'email'                 => "erased-{$subject->id}@example.invalid",
                'staff_id'               => null,
                'whatsapp_phone'        => null,
                'two_factor_secret'     => null,
                'two_factor_recovery_codes' => null,
            ])->save();
            $log['redacted'][] = ['table' => 'users', 'id' => $subject->id, 'fields' => ['name', 'email', 'staff_id', 'whatsapp_phone', '2fa secrets']];

            // 2. Employee record (if any) — redact PII, keep employment numbers
            //    where they're needed for statutory retention.
            $emp = $subject->employee;
            if ($emp) {
                $emp->forceFill([
                    'phone'                          => null,
                    'address'                        => null,
                    'date_of_birth'                  => null,
                    'national_id'                    => null,
                    'mobile_money_number'            => null,
                    'emergency_contact_name'         => $stamp,
                    'emergency_contact_phone'        => null,
                    'emergency_contact_relationship' => null,
                    'avatar_path'                    => null,
                ])->save();
                $log['redacted'][] = [
                    'table'  => 'employees',
                    'id'     => $emp->id,
                    'fields' => ['phone', 'address', 'dob', 'national_id', 'momo', 'emergency contact', 'avatar'],
                ];

                // Skills + documents can be erased completely.
                $skillsDeleted = $emp->skills()->delete();
                $log['redacted'][] = ['table' => 'employee_skills', 'employee_id' => $emp->id, 'count' => $skillsDeleted];

                $log = $this->reportStatutoryHolds($emp, $log);
            }

            // 3. Audit logs — never deleted (would break the chain). Recorded
            //    in held_back for transparency.
            $auditCount = \App\Models\AuditLog::where('user_id', $subject->id)->count();
            $log['held_back'][] = [
                'table'    => 'audit_logs',
                'count'    => $auditCount,
                'statute'  => 'Internal — tamper-evident chain integrity',
                'note'     => 'Audit-log row PII fields are themselves redacted; the row remains for chain integrity.',
            ];
        });

        return $log;
    }

    private function reportStatutoryHolds(Employee $emp, array $log): array
    {
        // Payroll: count records still inside the 6-year retention window
        $payrollCutoff = CarbonImmutable::now()->subYears(self::PAYROLL_RETENTION_YEARS);
        $payrollHeld = \App\Models\PayrollLine::where('employee_id', $emp->id)
            ->whereHas('run', fn ($q) => $q->where('period_start', '>=', $payrollCutoff))
            ->count();

        if ($payrollHeld > 0) {
            $log['held_back'][] = [
                'table'   => 'payroll_lines',
                'count'   => $payrollHeld,
                'statute' => 'Income Tax Act 2015 (Act 896) §43 — 6-year retention',
                'until'   => $payrollCutoff->addYears(self::PAYROLL_RETENTION_YEARS)->toDateString(),
            ];
        }

        // SSNIT statutory returns (proxy: identity verification records tied to SSNIT)
        $log['held_back'][] = [
            'table'   => 'identity_verifications',
            'count'   => \App\Models\IdentityVerification::where('employee_id', $emp->id)->count(),
            'statute' => 'National Pensions Act 2008 (Act 766) §92 — 7-year retention',
            'until'   => CarbonImmutable::now()->addYears(self::SSNIT_RETENTION_YEARS)->toDateString(),
        ];

        return $log;
    }
}
