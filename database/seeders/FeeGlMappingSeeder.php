<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FeeGlMapping;
use App\Models\GlAccount;
use Illuminate\Database\Seeder;

/**
 * Seeds a `fee_gl_mappings` row for every canonical website fee_code, plus the
 * 1131 Website Collections Clearing asset account that every collection debits
 * on receipt (bank reconciliation later matches it to actual settlements).
 *
 * Run AFTER CihrmChartOfAccountsSeeder — several income codes below (4110,
 * 4120, 4100, 2400) are structural CIHRM chart lines and are only *resolved*
 * here, not created.
 *
 * Income code choice: member.subscription/student.subscription/induction/
 * building_levy/combined/conference/exhibitor reuse existing CIHRM income
 * lines (4110, 4120, 4100, 4190, 4640, 4680) because they map semantically
 * to specific, already-audited accounts. The remaining fee types (tuition,
 * exemption, exam, transcript, premium) get NEW codes in the 47xx block
 * rather than the 4130-4180 codes an earlier draft of this seeder used —
 * those codes are already taken by unrelated CIHRM chart lines (e.g. 4150 is
 * "Corporate Membership", 4160 is "Sale of Admission Forms"), and GlAccount::
 * firstOrCreate() would have silently attached the website fee to that
 * existing, wrongly-named account instead of creating a new one. Codes 4730
 * and 4740 are no longer referenced (conference/exhibitor route to 4680
 * instead) and are intentionally not created by this seeder.
 */
class FeeGlMappingSeeder extends Seeder
{
    public function run(): void
    {
        $assetParentId  = GlAccount::where('code', '1000')->value('id');
        $incomeParentId = GlAccount::where('code', '4000')->value('id');

        // Clearing account money lands in on receipt; bank reconciliation later
        // matches it to actual settlements.
        $clearing = GlAccount::firstOrCreate(
            ['code' => '1131'],
            [
                'name'              => 'Website Collections Clearing',
                'type'              => 'asset',
                'parent_id'         => $assetParentId,
                'statement_section' => 'current',
            ],
        );

        // Ensure income accounts exist (create under the 4000 parent if absent).
        $income = fn (string $code, string $name) => GlAccount::firstOrCreate(
            ['code' => $code],
            [
                'name'              => $name,
                'type'              => 'income',
                'parent_id'         => $incomeParentId,
                'statement_section' => 'operating',
            ],
        );

        $deferred = GlAccount::where('code', '2400')->firstOrFail();

        // fee_code => [label, income code, deferred?, months]
        $map = [
            'member.subscription'   => ['Member subscription',        '4110', true,  12],
            'member.induction'      => ['Member induction fee',       '4190', false, null],
            'member.building_levy'  => ['Building levy',              '4640', false, null],
            'member.combined'       => ['Member combined fee',        '4100', false, null],
            'student.subscription'  => ['Student subscription',       '4120', true,  12],
            'student.tuition'       => ['Student tuition',            '4700', false, null],
            'student.exemption'     => ['Student exemption fee',      '4710', false, null],
            'student.combined'      => ['Student combined fee',       '4120', false, null],
            'exam'                  => ['Examination fee',            '4720', false, null],
            'conference'            => ['Conference fee',             '4680', false, null],
            'exhibitor'             => ['Exhibitor package',          '4680', false, null],
            'transcript'            => ['Transcript fee',             '4750', false, null],
            'premium'               => ['Premium fee',                '4760', false, null],
        ];

        foreach ($map as $code => [$label, $incomeCode, $isDeferred, $months]) {
            FeeGlMapping::updateOrCreate(
                ['fee_code' => $code],
                [
                    'label'                  => $label,
                    'income_gl_account_id'   => $income($incomeCode, $label.' income')->id,
                    'clearing_gl_account_id' => $clearing->id,
                    'is_deferred'            => $isDeferred,
                    'recognition_months'     => $months,
                    'deferred_gl_account_id' => $isDeferred ? $deferred->id : null,
                    'is_active'              => true,
                ],
            );
        }
    }
}
