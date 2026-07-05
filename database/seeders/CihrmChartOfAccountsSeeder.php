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
                    'name'      => $name,
                    'type'      => $type,
                    'parent_id' => $codeToId[$parentCode] ?? null,
                    'is_active' => true,
                    'currency'  => 'GHS',
                ]
            );
            $codeToId[$code] = $account->id;
        }
    }
}
