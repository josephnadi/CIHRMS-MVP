<?php

declare(strict_types=1);

namespace App\Services\Website;

use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Customer;
use App\Models\Member;

/**
 * Read-only member mirror. mvp's `Member` is a projection of the CIHRM
 * website's `users` table, keyed by the website's user id
 * (`external_user_id`). The website is the source of truth for member
 * identity/profile; mvp mirrors just enough (class, status, contact
 * details) to drive billing and GL drill-down. Every mirrored member
 * gets its own AR `Customer` since `members.customer_id` is required
 * and unique.
 */
class MemberMirrorService
{
    /** Upsert a member mirror from a website feed record, keyed by external_user_id. */
    public function upsert(array $r): Member
    {
        $member = Member::firstOrNew(['external_user_id' => $r['external_user_id']]);

        $member->fill([
            'external_user_id' => $r['external_user_id'],
            'member_no'        => $r['member_number'] ?? $member->member_no ?? ('WEB-'.$r['external_user_id']),
            'student_no'       => $r['student_number'] ?? $member->student_no,
            'class'            => $this->mapClass($r['class'] ?? null)->value,
            'status'           => $this->mapStatus($r['status'] ?? null)->value,
            'name'             => $r['name'] ?? $member->name,
            'email'            => $r['email'] ?? $member->email,
            'phone'            => $r['phone'] ?? $member->phone,
        ]);

        if (! empty($r['chartered_at'])) {
            $member->chartered_at = $r['chartered_at'];
        }

        if (! $member->customer_id) {
            $member->customer_id = Customer::create([
                'code'  => 'WEB-'.$r['external_user_id'],
                'name'  => $member->name ?? 'Member '.$r['external_user_id'],
                'email' => $member->email,
                'phone' => $member->phone,
            ])->id;
        }

        $member->save();

        return $member;
    }

    /**
     * Website membership classes (student|associate|full|fellow|chartered)
     * don't line up 1:1 with mvp's MemberClass enum — map explicitly rather
     * than casting the raw string, which would blow up on 'full'/'chartered'.
     */
    private function mapClass(?string $webClass): MemberClass
    {
        return match ($webClass) {
            'student'                  => MemberClass::Student,
            'associate'                => MemberClass::Associate,
            'professional', 'full'     => MemberClass::Professional,
            'fellow'                   => MemberClass::Fellow,
            'chartered'                => MemberClass::Fellow,
            'alumni'                   => MemberClass::Alumni,
            default                    => MemberClass::Student,
        };
    }

    /**
     * Website statuses (active|suspended|lapsed|resigned|deceased|inactive|
     * expired) mostly match mvp's MemberStatus enum; 'inactive'/'expired'
     * fold into 'lapsed'.
     */
    private function mapStatus(?string $webStatus): MemberStatus
    {
        return match ($webStatus) {
            'active'              => MemberStatus::Active,
            'suspended'           => MemberStatus::Suspended,
            'lapsed'              => MemberStatus::Lapsed,
            'resigned'            => MemberStatus::Resigned,
            'deceased'            => MemberStatus::Deceased,
            'inactive', 'expired' => MemberStatus::Lapsed,
            default               => MemberStatus::Active,
        };
    }
}
