<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MemberClass;
use App\Enums\MemberStatus;
use App\Models\Customer;
use App\Models\GlAccount;
use App\Models\Member;
use Illuminate\Database\Seeder;

class MemberPortalDemoSeeder extends Seeder
{
    private const MEMBERS = [
        [
            'member_no' => 'CIHRM-M-2026-00001',
            'class'     => MemberClass::Professional,
            'name'      => 'Kwame Member',
            'email'     => 'member@cihrms.local',
            'phone'     => '+233200000101',
            'address'   => 'Accra, Ghana',
            'dob'       => '1988-04-12',
            'notes'     => 'Fixed demo member portal account.',
        ],
        [
            'member_no' => 'CIHRM-S-2026-00001',
            'class'     => MemberClass::Student,
            'name'      => 'Ama Student',
            'email'     => 'student.member@cihrms.local',
            'phone'     => '+233200000102',
            'address'   => 'Kumasi, Ghana',
            'dob'       => '1998-09-21',
            'notes'     => 'Fixed demo student member portal account.',
        ],
    ];

    public function run(): void
    {
        $incomeGl = GlAccount::where('code', '4100')->first();
        $arGl = GlAccount::where('code', '1200')->first();

        foreach (self::MEMBERS as $demo) {
            $customer = Customer::updateOrCreate(
                ['code' => $demo['member_no']],
                [
                    'name'                         => $demo['name'],
                    'tax_id'                       => null,
                    'status'                       => 'active',
                    'email'                        => $demo['email'],
                    'phone'                        => $demo['phone'],
                    'address'                      => $demo['address'],
                    'default_income_gl_account_id' => $incomeGl?->id,
                    'default_ar_gl_account_id'     => $arGl?->id,
                    'notes'                        => $demo['notes'],
                ],
            );

            Member::updateOrCreate(
                ['email' => $demo['email']],
                [
                    'member_no'              => $demo['member_no'],
                    'class'                  => $demo['class']->value,
                    'status'                 => MemberStatus::Active->value,
                    'name'                   => $demo['name'],
                    'phone'                  => $demo['phone'],
                    'address'                => $demo['address'],
                    'date_of_birth'          => $demo['dob'],
                    'ghana_card_number_hash' => hash('sha256', $demo['member_no']),
                    'customer_id'            => $customer->id,
                    'chartered_at'           => $demo['class'] === MemberClass::Student ? null : now()->subYears(3),
                    'lapsed_at'              => null,
                    'password'               => bcrypt('password'),
                    'notes'                  => $demo['notes'],
                ],
            );
        }
    }
}
