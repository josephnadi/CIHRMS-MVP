<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIHRM payroll alignment: some allowances are not fixed cash amounts but are
 * computed from pay — e.g. Fuel benefit-in-kind = 5% of cash emolument capped
 * at GHS 625, and the Transport refund = 18% of basic (24% for directors).
 *
 * Add a calculation method to the allowance so these can be defined once and
 * recomputed each run. `fixed` is the default, so every existing allowance —
 * and every existing payroll result — is unchanged; only rows explicitly set to
 * a percentage method use `rate`/`cap`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allowances', function (Blueprint $table) {
            // fixed | percent_of_basic | percent_of_emolument
            $table->string('calc_method', 32)->default('fixed')->after('amount');
            // Percentage (e.g. 0.05 = 5%) for the percent_* methods.
            $table->decimal('rate', 8, 5)->nullable()->after('calc_method');
            // Optional monetary cap on the computed amount (e.g. GHS 625 fuel cap).
            $table->decimal('cap', 14, 2)->nullable()->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('allowances', function (Blueprint $table) {
            $table->dropColumn(['calc_method', 'rate', 'cap']);
        });
    }
};
