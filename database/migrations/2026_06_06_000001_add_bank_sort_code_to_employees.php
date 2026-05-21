<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the GhIPSS bank sort code to the employees table. Sort code is the
 * 5–7 digit branch identifier the GhIPSS network uses to route ACH credits
 * to the right Ghanaian bank branch. We previously only stored bank_name +
 * bank_account, which is enough for human-friendly UI but insufficient for
 * the bulk-payment file that gets uploaded to the sponsor bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('bank_sort_code', 16)->nullable()->after('bank_account');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('bank_sort_code');
        });
    }
};
