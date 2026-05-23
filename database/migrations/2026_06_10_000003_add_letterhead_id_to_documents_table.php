<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignId('letterhead_id')
                ->nullable()
                ->after('confidentiality')
                ->constrained('letterhead_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('letterhead_id');
        });
    }
};
