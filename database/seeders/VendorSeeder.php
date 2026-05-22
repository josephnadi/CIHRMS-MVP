<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlAccount;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    /**
     * Seeds 5 example Ghana vendors. Linked GL accounts (default expense + AP)
     * must already exist — run ChartOfAccountsSeeder first.
     * Idempotent: keyed on `code`.
     */
    private const VENDORS = [
        ['VEN-001', 'GCB Office Supplies',          'GH-TIN-100001', '5200', '2100', 'orders@gcboffice.gh',  '+233302100100'],
        ['VEN-002', 'Vodafone Ghana',               'GH-TIN-100002', '5300', '2100', 'billing@vodafone.gh',  '+233302222222'],
        ['VEN-003', 'Ghana Water Company',          'GH-TIN-100003', '5200', '2100', 'bills@gwc.gh',          '+233302333333'],
        ['VEN-004', 'Electricity Co. of Ghana',     'GH-TIN-100004', '5200', '2100', 'commercial@ecg.gh',     '+233302444444'],
        ['VEN-005', 'AccraStationery Ltd',          'GH-TIN-100005', '5200', '2100', 'sales@accrastat.gh',    '+233302555555'],
    ];

    public function run(): void
    {
        foreach (self::VENDORS as [$code, $name, $taxId, $expenseCode, $apCode, $email, $phone]) {
            $expenseGl = GlAccount::where('code', $expenseCode)->first();
            $apGl      = GlAccount::where('code', $apCode)->first();

            Vendor::updateOrCreate(
                ['code' => $code],
                [
                    'name'                          => $name,
                    'tax_id'                        => $taxId,
                    'status'                        => 'active',
                    'email'                         => $email,
                    'phone'                         => $phone,
                    'default_expense_gl_account_id' => $expenseGl?->id,
                    'default_ap_gl_account_id'      => $apGl?->id,
                ],
            );
        }
    }
}
