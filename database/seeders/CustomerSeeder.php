<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\GlAccount;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Seeds 5 example customers reflecting CIHRM's revenue mix.
     * Linked GL accounts (default income + AR) must already exist —
     * run ChartOfAccountsSeeder first. Idempotent: keyed on `code`.
     */
    private const CUSTOMERS = [
        // [code, name, tax_id, income_code, ar_code, email, phone]
        ['CUS-001', 'Acme Industries Ltd',                       'GH-TIN-200001', '4100', '1200', 'accounts@acme.gh',         '+233302600100'],
        ['CUS-002', 'Government of Ghana — Min of Finance',      'GH-TIN-200002', '4200', '1200', 'training@mofep.gov.gh',    '+233302600200'],
        ['CUS-003', 'Ghana National Bank — HR Dept',             'GH-TIN-200003', '4200', '1200', 'hr.training@gnb.gh',       '+233302600300'],
        ['CUS-004', 'Individual Member — A. K. Asante',          null,            '4100', '1200', 'ak.asante@example.com',    '+233244600400'],
        ['CUS-005', 'MTN Ghana — Training Programme',            'GH-TIN-200005', '4200', '1200', 'l&d@mtn.com.gh',            '+233244600500'],
    ];

    public function run(): void
    {
        foreach (self::CUSTOMERS as [$code, $name, $taxId, $incomeCode, $arCode, $email, $phone]) {
            $incomeGl = GlAccount::where('code', $incomeCode)->first();
            $arGl     = GlAccount::where('code', $arCode)->first();

            Customer::updateOrCreate(
                ['code' => $code],
                [
                    'name'                         => $name,
                    'tax_id'                       => $taxId,
                    'status'                       => 'active',
                    'email'                        => $email,
                    'phone'                        => $phone,
                    'default_income_gl_account_id' => $incomeGl?->id,
                    'default_ar_gl_account_id'     => $arGl?->id,
                ],
            );
        }
    }
}
