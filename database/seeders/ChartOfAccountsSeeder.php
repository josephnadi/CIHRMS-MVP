<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * NPO-flavored Ghana chart of accounts.
     * Structure: [code, name, type, parent_code|null].
     *
     * ⚠ ORDER IS LOAD-BEARING: each child MUST appear after its parent in this array.
     * Reordering without checking parent_code references will silently set parent_id to null,
     * because the parent lookup map is built during the same iteration.
     */
    private const ACCOUNTS = [
        // Assets (1xxx)
        ['1000', 'Assets',                      'asset', null],
        ['1010', 'Cash on Hand',                'asset', '1000'],
        ['1100', 'Bank — GCB Operating',        'asset', '1000'],
        ['1110', 'Bank — Stanbic Payroll',      'asset', '1000'],
        ['1120', 'Bank — ADB Statutory Escrow', 'asset', '1000'],
        ['1200', 'Accounts Receivable',         'asset', '1000'],
        ['1300', 'Loans Receivable from Staff', 'asset', '1000'],

        // Liabilities (2xxx)
        ['2000', 'Liabilities',                  'liability', null],
        ['2100', 'Accounts Payable',             'liability', '2000'],
        ['2200', 'SSNIT Payable',                'liability', '2000'],
        ['2210', 'PAYE Payable',                 'liability', '2000'],
        ['2220', 'Tier-2 Pension Payable',       'liability', '2000'],
        ['2230', 'Tier-3 Pension Payable',       'liability', '2000'],
        ['2240', 'NHIA Payable',                 'liability', '2000'],
        ['2300', 'Salaries Payable',             'liability', '2000'],

        // Equity (3xxx)
        ['3000', 'Equity',                  'equity', null],
        ['3100', 'General Fund',            'equity', '3000'],
        ['3200', 'Accumulated Surplus',     'equity', '3000'],

        // Income (4xxx)
        ['4000', 'Income',                  'income', null],
        ['4100', 'Membership Dues',         'income', '4000'],
        ['4200', 'Course Fees',             'income', '4000'],
        ['4300', 'Certification Fees',      'income', '4000'],
        ['4400', 'Donations & Grants',      'income', '4000'],
        ['4500', 'Other Income',            'income', '4000'],

        // Expense (5xxx)
        ['5000', 'Expenses',                       'expense', null],
        ['5100', 'Salaries Expense',               'expense', '5000'],
        ['5110', 'Allowances Expense',             'expense', '5000'],
        ['5120', 'Statutory Employer Contributions','expense', '5000'],
        ['5200', 'Operations Expense',             'expense', '5000'],
        ['5300', 'IT & Technology',                'expense', '5000'],
        ['5400', 'Marketing',                      'expense', '5000'],
        ['5500', 'Other Expenses',                 'expense', '5000'],
    ];

    public function run(): void
    {
        $codeToId = [];

        foreach (self::ACCOUNTS as [$code, $name, $type, $parentCode]) {
            $parentId = $parentCode === null ? null : ($codeToId[$parentCode] ?? null);

            $account = GlAccount::updateOrCreate(
                ['code' => $code],
                [
                    'name'      => $name,
                    'type'      => $type,
                    'parent_id' => $parentId,
                    'is_active' => true,
                    'currency'  => 'GHS',
                ]
            );

            $codeToId[$code] = $account->id;
        }
    }
}
