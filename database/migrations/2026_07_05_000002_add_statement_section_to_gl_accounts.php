<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statement presentation mapping. CIHRM's Income & Expenditure splits income
 * into Operating Income (Note 11) and Other Income (Note 12) with a Net
 * Operating Income subtotal, and the SOFP groups assets current / non-current
 * and equity as Member's Fund. `statement_section` records where each account
 * sits so the reports can present the audited layout.
 *
 * Nullable — accounts without it fall back to their account-type default
 * (income → operating), so nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_accounts', function (Blueprint $table) {
            // income:   operating | other
            // asset:    current | non_current
            // equity:   members_fund
            $table->string('statement_section', 24)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('gl_accounts', function (Blueprint $table) {
            $table->dropColumn('statement_section');
        });
    }
};
