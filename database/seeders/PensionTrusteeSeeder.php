<?php

namespace Database\Seeders;

use App\Models\PensionTrustee;
use Illuminate\Database\Seeder;

/**
 * Seed NPRA-licensed corporate trustees used by Tier-2 occupational pensions
 * in Ghana. Numbers are illustrative; production data should be confirmed
 * against the NPRA register before pilot.
 */
class PensionTrusteeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['Enterprise Trustees Ltd',    'NPRA-CT-001', 'corporate@enterprisegroup.com.gh'],
            ['Petra Trust Company Ltd',    'NPRA-CT-002', 'service@petratrust.com'],
            ['Glico Pensions Trustee Co.', 'NPRA-CT-003', 'pensions@glico.com'],
            ['Standard Pensions Trust',    'NPRA-CT-004', 'info@standardpensions.com'],
            ['Old Mutual Pensions Trust',  'NPRA-CT-005', 'pensions.gh@oldmutual.com'],
        ];

        foreach ($rows as [$name, $licence, $email]) {
            PensionTrustee::updateOrCreate(
                ['npra_license_number' => $licence],
                [
                    'name'             => $name,
                    'contact_email'    => $email,
                    'schedule_format'  => 'csv',
                    'is_active'        => true,
                    'schedule_columns' => null,
                ],
            );
        }
    }
}
