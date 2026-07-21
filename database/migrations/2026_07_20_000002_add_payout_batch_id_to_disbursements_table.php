<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->foreignId('payout_batch_id')->nullable()->after('id')
                ->constrained('payout_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_batch_id');
        });
    }
};
