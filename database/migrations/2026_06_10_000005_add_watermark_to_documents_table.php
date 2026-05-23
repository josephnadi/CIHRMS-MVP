<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignId('watermark_id')
                ->nullable()
                ->after('letterhead_id')
                ->constrained('watermark_templates')
                ->nullOnDelete();
            $t->string('watermark_mode', 10)->default('on_burn');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('watermark_id');
            $t->dropColumn('watermark_mode');
        });
    }
};
