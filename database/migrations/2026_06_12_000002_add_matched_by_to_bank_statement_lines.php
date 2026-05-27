<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L9 audit fix — redundant audit columns on bank_statement_lines.
 *
 * The matcher's identity already lives on the BankTransactionMatch row
 * (the link record). If that row is later deleted, the line's own
 * `reconciled_at` is the only surviving trail and there's no record of
 * WHO did the matching. These two columns add that trail directly on
 * the line, so a deletion of the match row doesn't erase the forensic
 * fingerprint.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->foreignId('matched_by')->nullable()->after('reconciled_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable()->after('matched_by');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_by');
            $table->dropColumn('matched_at');
        });
    }
};
