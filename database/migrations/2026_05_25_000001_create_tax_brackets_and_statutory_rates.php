<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated reference data for the statutory payroll engine.
 *
 * `tax_brackets` and `statutory_rates` are versioned by an open-ended interval
 * (`effective_from`, `effective_to`). A payroll run for any historical period
 * is reproducible: the engine selects the row whose interval contains the
 * pay-period date, never the "current" row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_brackets', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 8)->default('GH'); // Future-proof if we run multi-country
            $table->string('cadence', 16)->default('monthly'); // monthly | annual
            $table->decimal('lower_bound', 14, 2);
            $table->decimal('upper_bound', 14, 2)->nullable(); // null = open-ended top band
            $table->decimal('rate', 6, 4); // 0.0500 = 5%
            $table->decimal('cumulative_tax_at_lower', 14, 2)->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['jurisdiction', 'cadence', 'effective_from'], 'tax_brackets_lookup_idx');
        });

        Schema::create('statutory_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64); // SSNIT_EMPLOYER, SSNIT_EMPLOYEE, NHIA_SPLIT, TIER2_EMPLOYER, TIER2_EMPLOYEE, TIER3_MAX_COMBINED, MAX_INSURABLE_EARNINGS
            $table->string('label');
            $table->decimal('rate', 14, 6); // 0.135000, or absolute amount for caps
            $table->boolean('is_rate')->default(true); // false = absolute amount (e.g. MAX_INSURABLE_EARNINGS)
            $table->string('currency', 3)->default('GHS');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['code', 'effective_from'], 'statutory_rates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_rates');
        Schema::dropIfExists('tax_brackets');
    }
};
