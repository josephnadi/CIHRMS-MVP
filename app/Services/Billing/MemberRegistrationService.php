<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Customer;
use App\Models\Member;
use App\Models\User;
use App\Services\Finance\SequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Registers a new CIHRM member or student, atomically creating both the
 * institute-side `Member` row AND the AR-side `Customer` row (1:1) so
 * the existing AR pipeline can charge them immediately. Idempotent on
 * email — re-registering the same email returns the existing record.
 *
 * The `member_no` follows the convention `CIHRM-M-YYYY-NNNNN` (members)
 * or `CIHRM-S-YYYY-NNNNN` (students), generated via the
 * race-safe `SequenceService::next()` (the same primitive Finance F1–F5
 * uses for invoice / receipt references).
 */
class MemberRegistrationService
{
    public function __construct(private readonly SequenceService $sequences) {}

    /**
     * @param  array<string, mixed>  $data Member attributes:
     *   - class (MemberClass|string, required)
     *   - name (string, required)
     *   - email (string, optional but unique-if-set)
     *   - phone (string, optional)
     *   - address, date_of_birth, ghana_card_number (raw — hashed before storage)
     *   - status (MemberStatus|string, defaults to Active)
     */
    public function register(array $data, ?User $createdBy = null): Member
    {
        $class = $this->resolveClass($data['class'] ?? null);

        if (!empty($data['email'])) {
            $existing = Member::where('email', $data['email'])->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($data, $class, $createdBy) {
            $year = now()->year;
            $key  = $class === MemberClass::Student ? "member_student:{$year}" : "member:{$year}";
            $seq  = str_pad((string) $this->sequences->next($key), 5, '0', STR_PAD_LEFT);
            $prefix = $class === MemberClass::Student ? 'CIHRM-S' : 'CIHRM-M';
            $memberNo = "{$prefix}-{$year}-{$seq}";

            // 1. Create the AR-side Customer first (Member.customer_id FK).
            $customer = Customer::create([
                'code'    => $memberNo,
                'name'    => $data['name'],
                'email'   => $data['email']  ?? null,
                'phone'   => $data['phone']  ?? null,
                'address' => $data['address'] ?? null,
                'status'  => 'active',
            ]);

            // 2. Create the Member, linking to the Customer.
            $status = $this->resolveStatus($data['status'] ?? null);
            $ghanaCardHash = !empty($data['ghana_card_number'])
                ? hash('sha256', (string) $data['ghana_card_number'])
                : ($data['ghana_card_number_hash'] ?? null);

            return Member::create([
                'member_no'              => $memberNo,
                'class'                  => $class->value,
                'status'                 => $status->value,
                'name'                   => $data['name'],
                'email'                  => $data['email']         ?? null,
                'phone'                  => $data['phone']         ?? null,
                'address'                => $data['address']       ?? null,
                'date_of_birth'          => $data['date_of_birth'] ?? null,
                'ghana_card_number_hash' => $ghanaCardHash,
                'customer_id'            => $customer->id,
                'chartered_at'           => $data['chartered_at']  ?? null,
                'notes'                  => $data['notes']         ?? null,
            ]);
        });
    }

    private function resolveClass(MemberClass|string|null $value): MemberClass
    {
        if ($value instanceof MemberClass) return $value;
        if (is_string($value)) {
            $c = MemberClass::tryFrom($value);
            if ($c) return $c;
        }
        throw new DomainException('A valid member class is required.');
    }

    private function resolveStatus(MemberStatus|string|null $value): MemberStatus
    {
        if ($value instanceof MemberStatus) return $value;
        if (is_string($value)) {
            $s = MemberStatus::tryFrom($value);
            if ($s) return $s;
        }
        return MemberStatus::Active;
    }
}
