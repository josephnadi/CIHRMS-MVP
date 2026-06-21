<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit fix: payroll lines, loan accounts and final settlements cascaded on
 * delete of their employee / offboarding-case parent. A hard delete of an
 * employee would therefore silently destroy posted payroll history, loan
 * ledgers and settlement records — financial evidence that must never vanish
 * as a side effect. Switch these to RESTRICT so the parent delete is refused
 * while dependent financial rows exist (the app soft-deletes employees anyway;
 * this guards the forceDelete / manual path).
 */
return new class extends Migration
{
    /** @var array<int, array{0:string,1:string,2:string}> [table, column, parent] */
    private array $fks = [
        ['payroll_lines',     'employee_id',         'employees'],
        ['loan_accounts',     'employee_id',         'employees'],
        ['final_settlements', 'offboarding_case_id', 'offboarding_cases'],
    ];

    public function up(): void
    {
        foreach ($this->fks as [$table, $column, $parent]) {
            Schema::table($table, function (Blueprint $t) use ($column, $parent) {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($parent)->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->fks as [$table, $column, $parent]) {
            Schema::table($table, function (Blueprint $t) use ($column, $parent) {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($parent)->cascadeOnDelete();
            });
        }
    }
};
