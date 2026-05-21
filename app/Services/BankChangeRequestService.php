<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\PendingBankChange;
use App\Models\User;
use App\Services\Messaging\Sms\Contracts\SmsProvider;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Bank-account change workflow with USSD/SMS two-factor confirmation.
 *
 * Why this exists: payroll redirection is the most common HR-system fraud.
 * Without 2FA on bank-detail edits, anyone with `employees.manage` permission
 * (or whose admin account is compromised) could redirect the entire payroll
 * to attacker-controlled accounts just before a run closes.
 *
 *   request()  → creates a pending row, generates a 6-digit code, SMSes it
 *                to the employee's registered phone, returns the row
 *   confirm()  → checks the code, marks confirmed, applies the change to
 *                `employees.*` in a single transaction
 *   reject()   → marks rejected with a reason, leaves bank fields untouched
 *
 * Each pending change has a fresh 6-digit code, a 30-minute expiry, and a
 * 5-failed-attempt lockout. Codes are stored hashed (Hash::make) so a DB
 * leak doesn't expose live confirmation codes.
 */
class BankChangeRequestService
{
    public const CODE_TTL_MINUTES   = 30;
    public const MAX_FAILED_ATTEMPTS = 5;

    public function __construct(private readonly SmsProvider $sms) {}

    /**
     * Stage a bank-account change request. Snapshots the old values, hashes
     * the new code, sends it to the employee's phone. Any prior pending row
     * for this employee is cancelled — only one open change at a time.
     */
    public function request(Employee $employee, array $newBank, ?User $actor = null): PendingBankChange
    {
        $newAccount = trim((string) ($newBank['bank_account'] ?? ''));
        if ($newAccount === '') {
            throw new DomainException('new bank account is required');
        }

        $code = $this->generateCode();

        $pending = DB::transaction(function () use ($employee, $newBank, $newAccount, $code, $actor) {
            // Supersede any in-flight request for this employee.
            PendingBankChange::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->update([
                    'status'           => 'rejected',
                    'rejected_at'      => now(),
                    'rejection_reason' => 'Superseded by new request',
                ]);

            return PendingBankChange::create([
                'employee_id'        => $employee->id,
                'requested_by'       => $actor?->id,
                'old_bank_name'      => $employee->bank_name,
                'old_bank_account'   => $employee->bank_account,
                'old_bank_sort_code' => $employee->bank_sort_code,
                'new_bank_name'      => $newBank['bank_name'] ?? $employee->bank_name,
                'new_bank_account'   => $newAccount,
                'new_bank_sort_code' => $newBank['bank_sort_code'] ?? $employee->bank_sort_code,
                'code_hash'          => Hash::make($code),
                'code_expires_at'    => now()->addMinutes(self::CODE_TTL_MINUTES),
                'status'             => 'pending',
            ]);
        });

        // Send the code via SMS to the employee's registered phone. We use
        // the employee.phone (mobile) — NOT whatsapp_phone — because USSD
        // and SMS routes through the same Hubtel-style stack.
        $phone = (string) ($employee->phone ?? '');
        if ($phone !== '') {
            $this->sms->send(
                $phone,
                "Your bank-change code is {$code}. Dial *920# option 5 to confirm. "
                . "If you didn't request this, ignore. Expires in "
                . self::CODE_TTL_MINUTES . " minutes.",
            );
        }

        return $pending;
    }

    /**
     * Confirm a pending change with the 6-digit code. On success the change
     * is applied to `employees.*` in a single transaction, the row moves
     * pending → applied, and the audit log captures the before/after.
     */
    public function confirm(PendingBankChange $pending, string $code): PendingBankChange
    {
        if ($pending->status !== 'pending') {
            throw new DomainException("Request is {$pending->status}, can't confirm.");
        }
        if ($pending->isExpired()) {
            $pending->update(['status' => 'expired']);
            throw new DomainException('Confirmation code expired. Request a new change.');
        }
        if ($pending->failed_attempts >= self::MAX_FAILED_ATTEMPTS) {
            $pending->update(['status' => 'rejected', 'rejection_reason' => 'Too many failed attempts']);
            throw new DomainException('Too many failed attempts. Request a new change.');
        }

        if (! $pending->verifyCode($code)) {
            $pending->increment('failed_attempts');
            throw new DomainException('Wrong code.');
        }

        DB::transaction(function () use ($pending) {
            $pending->employee->update([
                'bank_name'      => $pending->new_bank_name,
                'bank_account'   => $pending->new_bank_account,
                'bank_sort_code' => $pending->new_bank_sort_code,
            ]);
            $pending->update([
                'status'       => 'applied',
                'confirmed_at' => now(),
                'applied_at'   => now(),
            ]);
        });

        return $pending->fresh();
    }

    /**
     * Subject rejects the change — typically because they didn't initiate it,
     * which is the explicit fraud signal. Reason text is shown to HR.
     */
    public function reject(PendingBankChange $pending, string $reason = 'Rejected by subject'): PendingBankChange
    {
        if ($pending->status !== 'pending') {
            throw new DomainException("Request is {$pending->status}, can't reject.");
        }
        $pending->update([
            'status'           => 'rejected',
            'rejected_at'      => now(),
            'rejection_reason' => mb_substr($reason, 0, 120),
        ]);
        return $pending->fresh();
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
