<?php

namespace App\Services\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Events\DuplicateIdentityDetected;
use App\Events\IdentityVerified;
use App\Models\Employee;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\Identity\Contracts\IdentityVerificationProvider;
use Illuminate\Support\Facades\DB;

/**
 * Ghana Card verification orchestrator.
 *
 *   - Selects the active provider from `config/identity.php`
 *   - Hashes the Ghana Card number for duplicate detection
 *   - Persists outcome to `identity_verifications`
 *   - Fires `IdentityVerified` on success and `DuplicateIdentityDetected`
 *     if the same hash already maps to a different employee
 */
class IdentityVerificationService
{
    public function __construct(private readonly IdentityVerificationProvider $provider) {}

    public function verify(Employee $employee, string $ghanaCardNumber, ?User $actor = null, ?string $evidencePath = null): IdentityVerification
    {
        $hash    = IdentityVerification::hashCardNumber($ghanaCardNumber);
        $result  = $this->provider->verify($ghanaCardNumber, $this->personalPayload($employee));

        $verification = DB::transaction(function () use ($employee, $ghanaCardNumber, $hash, $result, $actor, $evidencePath) {
            return IdentityVerification::create([
                'employee_id'        => $employee->id,
                'provider'           => $this->provider->kind(),
                'ghana_card_number'  => $ghanaCardNumber,
                'ghana_card_hash'    => $hash,
                'status'             => $result->success
                    ? IdentityVerificationStatus::Verified->value
                    : IdentityVerificationStatus::Failed->value,
                'verified_at'        => $result->success ? now() : null,
                'verified_by'        => $actor?->id,
                'expires_at'         => $result->expiresAt?->format('Y-m-d H:i:s'),
                'evidence_path'      => $evidencePath,
                'raw_response'       => $result->raw,
                'failure_reason'     => $result->reason,
            ]);
        });

        // Backfill the employee's national_id for quick lookups (encrypted by cast on the verification row).
        if ($result->success && empty($employee->national_id)) {
            $employee->forceFill(['national_id' => $ghanaCardNumber])->save();
        }

        if ($result->success) {
            $this->detectDuplicates($hash, $employee);
            event(new IdentityVerified($verification));
        }

        return $verification;
    }

    private function personalPayload(Employee $employee): array
    {
        return [
            'full_name'     => $employee->user?->name,
            'date_of_birth' => $employee->date_of_birth?->format('Y-m-d'),
            'phone'         => $employee->phone,
        ];
    }

    private function detectDuplicates(string $hash, Employee $current): void
    {
        $duplicates = IdentityVerification::where('ghana_card_hash', $hash)
            ->where('status', IdentityVerificationStatus::Verified->value)
            ->where('employee_id', '!=', $current->id)
            ->with('employee')
            ->get();

        if ($duplicates->isEmpty()) return;

        $employees = $duplicates->pluck('employee')->filter()->all();
        $employees[] = $current;

        event(new DuplicateIdentityDetected($hash, array_values($employees)));
    }
}
