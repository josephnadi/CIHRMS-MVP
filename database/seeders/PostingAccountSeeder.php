<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlAccount;
use App\Models\PostingAccount;
use Illuminate\Database\Seeder;

class PostingAccountSeeder extends Seeder
{
    /**
     * Default account-determination map.
     * Shape: [slug, account_code, domain, description, locked].
     * `locked` rows are system-critical and not re-pointable from the admin UI.
     */
    private const RULES = [
        ['payroll.salary_expense',           '5100', 'payroll', 'Gross basic salary expense',        true],
        ['payroll.allowance_expense',        '5110', 'payroll', 'Allowances expense',                false],
        ['payroll.employer_contrib_expense', '5120', 'payroll', 'Employer statutory contributions',  false],
        ['payroll.paye_payable',             '2210', 'payroll', 'PAYE withheld, owed to GRA',         true],
        ['payroll.ssnit_payable',            '2200', 'payroll', 'SSNIT owed (employee + employer)',   true],
        ['payroll.tier2_payable',            '2220', 'payroll', 'Tier-2 pension owed',                true],
        ['payroll.tier3_payable',            '2230', 'payroll', 'Tier-3 pension owed',                false],
        ['payroll.net_pay_payable',          '2300', 'payroll', 'Net pay owed to staff',             true],
        ['loan.principal_receivable',        '1300', 'loans',   'Staff loan principal receivable',    true],
        ['loan.interest_income',             '4600', 'loans',   'Loan interest income',               false],
        ['member_fee.receivable',            '1200', 'member_fees', 'Member fee receivable',          false],
        ['member_fee.income',                '4100', 'member_fees', 'Membership dues income',         false],
        ['bank.cash_in_transit',             '1130', 'bank',    'Disbursement clearing / in transit', false],
    ];

    public function run(): void
    {
        $codeToId = GlAccount::pluck('id', 'code');

        foreach (self::RULES as [$slug, $code, $domain, $description, $locked]) {
            $glId = $codeToId[$code] ?? null;
            if ($glId === null) {
                throw new \RuntimeException("PostingAccountSeeder: GL account {$code} not found for slug {$slug}.");
            }

            PostingAccount::updateOrCreate(
                ['slug' => $slug],
                [
                    'gl_account_id' => $glId,
                    'domain'        => $domain,
                    'description'   => $description,
                    'locked'        => $locked,
                ],
            );
        }
    }
}
