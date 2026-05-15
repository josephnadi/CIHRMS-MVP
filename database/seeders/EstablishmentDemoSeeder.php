<?php

namespace Database\Seeders;

use App\Enums\FundingSource;
use App\Enums\PositionStatus;
use App\Models\Department;
use App\Models\Grade;
use App\Models\GradeStep;
use App\Models\Position;
use Illuminate\Database\Seeder;

/**
 * Demo single-spine grade structure + a small set of seed positions
 * so the establishment UI has something to render out of the box.
 */
class EstablishmentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $grades = [
            ['GS-08', 'Junior Officer',  8,  1, 8,  2_800.00],
            ['GS-10', 'Officer',         10, 1, 8,  4_500.00],
            ['GS-12', 'Senior Officer',  12, 1, 8,  7_200.00],
            ['GS-14', 'Principal',       14, 1, 6, 11_500.00],
            ['GS-16', 'Assistant Director',16, 1, 6, 17_500.00],
            ['GS-18', 'Deputy Director', 18, 1, 4, 25_000.00],
            ['GS-20', 'Director',        20, 1, 4, 36_000.00],
        ];

        foreach ($grades as [$code, $name, $level, $minStep, $maxStep, $baseStep1]) {
            $grade = Grade::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'level' => $level, 'min_step' => $minStep, 'max_step' => $maxStep],
            );

            for ($step = $minStep; $step <= $maxStep; $step++) {
                $factor = 1.0 + (($step - 1) * 0.045); // 4.5% increment per step
                GradeStep::updateOrCreate(
                    ['grade_id' => $grade->id, 'step' => $step, 'effective_from' => '2026-01-01'],
                    ['base_salary' => round($baseStep1 * $factor, 2), 'currency' => 'GHS', 'effective_to' => null],
                );
            }
        }

        // Wire HR & Marketing demo departments with positions if they exist.
        $hr  = Department::where('code', 'HR')->first();
        $mkt = Department::where('code', 'MKT')->first();

        $samples = [
            ['HR-DIR-001', 'HR Director',        'GS-20', $hr?->id,  true],
            ['HR-MGR-001', 'HR Manager',         'GS-16', $hr?->id,  true],
            ['HR-OFF-001', 'HR Officer',         'GS-10', $hr?->id,  false],
            ['FIN-OFF-001','Finance Officer',    'GS-12', $hr?->id,  false],
            ['IT-LEAD-001','IT Support Lead',    'GS-14', $hr?->id,  true],
            ['MKT-LEAD-001','Marketing Lead',    'GS-14', $mkt?->id, true],
        ];

        foreach ($samples as [$code, $title, $gradeCode, $deptId, $isSupervisory]) {
            if (! $deptId) continue;
            $grade = Grade::where('code', $gradeCode)->first();
            if (! $grade) continue;

            Position::updateOrCreate(
                ['code' => $code],
                [
                    'title'             => $title,
                    'grade_id'          => $grade->id,
                    'department_id'     => $deptId,
                    'cost_center'       => $deptId . '-ops',
                    'funding_source'    => FundingSource::Gog->value,
                    'status'            => PositionStatus::Vacant->value,
                    'headcount_ceiling' => 1,
                    'is_supervisory'    => $isSupervisory,
                ],
            );
        }
    }
}
