<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrgBankAccountPurpose;
use App\Models\GlAccount;
use App\Models\OrgBankAccount;
use Illuminate\Database\Seeder;

class OrgBankAccountSeeder extends Seeder
{
    /**
     * Seeds 3 bank accounts. Linked GL accounts (codes 1100/1110/1120) must
     * already exist — run ChartOfAccountsSeeder first.
     */
    private const BANKS = [
        ['1100', 'GCB', 'Head Office', 'CIHRM — Operating',         '1010000012345', 'GH010100', OrgBankAccountPurpose::Operating],
        ['1110', 'Stanbic', 'Accra Main', 'CIHRM — Payroll',         '9040000098765', 'GH050100', OrgBankAccountPurpose::Payroll],
        ['1120', 'ADB', 'Achimota',     'CIHRM — Statutory Escrow', '0501000054321', 'GH080100', OrgBankAccountPurpose::StatutoryEscrow],
    ];

    public function run(): void
    {
        foreach (self::BANKS as [$glCode, $bankName, $branch, $accountName, $accountNumber, $sortCode, $purpose]) {
            $gl = GlAccount::where('code', $glCode)->first();
            if (! $gl) continue;

            OrgBankAccount::updateOrCreate(
                ['bank_name' => $bankName, 'account_number' => $accountNumber],
                [
                    'gl_account_id'   => $gl->id,
                    'branch'          => $branch,
                    'account_name'    => $accountName,
                    'sort_code'       => $sortCode,
                    'currency'        => 'GHS',
                    'purpose'         => $purpose->value,
                    'opening_balance' => 0,
                    'is_active'       => true,
                ]
            );
        }
    }
}
