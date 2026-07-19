<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only trail of asset-audit activity. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_audit_id')->constrained('asset_audits')->cascadeOnDelete();
            $table->foreignId('asset_audit_line_id')->nullable()->constrained('asset_audit_lines')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audit_events');
    }
};
