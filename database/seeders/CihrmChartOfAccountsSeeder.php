<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlAccount;
use Illuminate\Database\Seeder;

/**
 * CIHRM-specific chart of accounts, taken from the audited Annual Financial
 * Statements (IFRS for SMEs, Act 1020). Layers CIHRM's real income, expenditure,
 * asset, liability and Member's Fund lines on top of the structural accounts in
 * ChartOfAccountsSeeder (which owns the control accounts the posting engine uses
 * — AR 1200, AP 2100, bank/statutory codes, etc.).
 *
 * Additive and idempotent: every account upserts by code and the structural
 * parents (1000/2000/3000/4000/5000) are reused, so existing balances, posting
 * rules and tests are untouched. Run AFTER ChartOfAccountsSeeder.
 */
class CihrmChartOfAccountsSeeder extends Seeder
{
    /** [code, name, type, parent_code] */
    private const ACCOUNTS = [
        // ── Non-current & other assets (Statement of Financial Position) ──
        ['1400', 'Property, Plant & Equipment',        'asset', '1000'],
        ['1410', 'Intangible Assets',                  'asset', '1000'],
        ['1500', 'Inventory',                          'asset', '1000'],
        ['1600', 'Short-Term Investment',              'asset', '1000'],

        // ── Payables / deferred income (Note 10) ──
        ['2400', 'Subscription in Advance (Deferred Income)', 'liability', '2000'],
        ['2410', 'Accrued Withholding Taxes & Surcharges',    'liability', '2000'],
        ['2420', 'Staff Separation Payables',                 'liability', '2000'],
        ['2430', 'Allowance & Other Payables',                'liability', '2000'],
        ['2440', 'Statutory Deductions Payable',              'liability', '2000'],

        // ── Member's Fund (equity) ──
        ['3300', 'Accumulated Fund',   'equity', '3000'],
        ['3400', 'Revaluation Reserve', 'equity', '3000'],

        // ── Operating Income (Note 11) ──
        ['4110', 'Subscription - Members',                'income', '4000'],
        ['4120', 'PCP Fees & Subscription - Students',    'income', '4000'],
        ['4130', 'Fees from CPD Programme',               'income', '4000'],
        ['4140', 'Chartered HRM Practitioner',            'income', '4000'],
        ['4150', 'Corporate Membership',                  'income', '4000'],
        ['4160', 'Sale of Admission Forms',               'income', '4000'],
        ['4170', 'Sale of Membership Forms',              'income', '4000'],
        ['4180', 'Affiliates',                            'income', '4000'],
        ['4190', 'Income from Induction',                 'income', '4000'],

        // ── Other Income (Note 12) — 4600 Interest Income already exists ──
        ['4610', 'Income from Graduation',                'income', '4000'],
        ['4620', 'Income from HR Consultancy Services',   'income', '4000'],
        ['4630', 'Hall Rental Income',                    'income', '4000'],
        ['4640', 'Building Complex Levy',                 'income', '4000'],
        ['4650', 'Sale of Branded Merchandise',           'income', '4000'],
        ['4660', 'Sale of Past Questions & Forms',        'income', '4000'],
        ['4670', 'Sale of HR & Labour Act',               'income', '4000'],
        ['4680', 'Conference Income',                     'income', '4000'],

        // ── Expenditure (Note 13) ──
        ['5700', 'Professional Certification Programme Expenses', 'expense', '5000'],
        ['5701', 'Continuous Professional Development',           'expense', '5000'],
        ['5702', 'Advertisement & Communication',                'expense', '5000'],
        ['5703', 'Telephone & Postage',                          'expense', '5000'],
        ['5704', 'Office Expenses',                              'expense', '5000'],
        ['5705', 'Generator Expenses',                           'expense', '5000'],
        ['5706', 'Cleaning & Sanitation',                        'expense', '5000'],
        ['5707', 'Repairs & Maintenance',                        'expense', '5000'],
        ['5708', 'Vehicle Running Expenses',                     'expense', '5000'],
        ['5709', 'Bank Charges',                                 'expense', '5000'],
        ['5710', 'Insurance',                                    'expense', '5000'],
        ['5711', 'Seconded Staff, Interns & National Service',   'expense', '5000'],
        ['5712', 'Registration & Licences',                      'expense', '5000'],
        ['5713', 'Printing & Stationery',                        'expense', '5000'],
        ['5714', 'Council Meeting Expenses',                     'expense', '5000'],
        ['5715', 'P.C.B Expenses',                               'expense', '5000'],
        ['5716', 'A.G.M Expenses',                               'expense', '5000'],
        ['5717', 'Graduation Expenses',                          'expense', '5000'],
        ['5718', 'Security Services',                            'expense', '5000'],
        ['5719', 'Consultancy Expenses',                         'expense', '5000'],
        ['5720', 'Utility Charges',                              'expense', '5000'],
        ['5721', 'Committee Expenses',                           'expense', '5000'],
        ['5722', 'Medical Expenses',                             'expense', '5000'],
        ['5723', 'Training Expenses',                            'expense', '5000'],
        ['5724', 'Staff Cost',                                   'expense', '5000'],
        ['5725', 'Depreciation & Amortisation',                  'expense', '5000'],
    ];

    /**
     * Statement placement per account code — where each line sits on the
     * Income & Expenditure and the SOFP. Covers both these CIHRM accounts and
     * the generic structural accounts so the reports present the audited layout.
     *   income → operating | other · asset → current | non_current · equity → members_fund
     */
    private const SECTIONS = [
        // Assets
        '1010' => 'current', '1100' => 'current', '1110' => 'current', '1120' => 'current',
        '1130' => 'current', '1200' => 'current', '1300' => 'current',
        '1400' => 'non_current', '1410' => 'non_current', '1500' => 'current', '1600' => 'current',
        // Liabilities (CIHRM has only current liabilities)
        '2400' => 'current', '2410' => 'current', '2420' => 'current', '2430' => 'current', '2440' => 'current',
        // Equity → Member's Fund
        '3100' => 'members_fund', '3200' => 'members_fund', '3300' => 'members_fund', '3400' => 'members_fund',
        // Operating income
        '4100' => 'operating', '4200' => 'operating', '4300' => 'operating',
        '4110' => 'operating', '4120' => 'operating', '4130' => 'operating', '4140' => 'operating',
        '4150' => 'operating', '4160' => 'operating', '4170' => 'operating', '4180' => 'operating', '4190' => 'operating',
        // Other income
        '4400' => 'other', '4500' => 'other', '4600' => 'other',
        '4610' => 'other', '4620' => 'other', '4630' => 'other', '4640' => 'other',
        '4650' => 'other', '4660' => 'other', '4670' => 'other', '4680' => 'other',
    ];

    public function run(): void
    {
        // Resolve structural parents already seeded by ChartOfAccountsSeeder.
        $codeToId = GlAccount::whereIn('code', ['1000', '2000', '3000', '4000', '5000'])
            ->pluck('id', 'code')
            ->all();

        foreach (self::ACCOUNTS as [$code, $name, $type, $parentCode]) {
            $account = GlAccount::updateOrCreate(
                ['code' => $code],
                [
                    'name'              => $name,
                    'type'              => $type,
                    'statement_section' => self::SECTIONS[$code] ?? null,
                    'parent_id'         => $codeToId[$parentCode] ?? null,
                    'is_active'         => true,
                    'currency'          => 'GHS',
                ]
            );
            $codeToId[$code] = $account->id;
        }

        // Classify the generic structural accounts too, so the statements group them.
        foreach (self::SECTIONS as $code => $section) {
            GlAccount::where('code', $code)->update(['statement_section' => $section]);
        }
    }
}
