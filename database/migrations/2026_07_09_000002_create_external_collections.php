<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_collections', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->unsignedBigInteger('source_id');
            $table->string('external_ref');
            $table->unsignedBigInteger('external_user_id')->nullable();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('fee_code');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('GHS');
            $table->timestamp('paid_at');
            $table->string('method')->nullable();
            $table->string('gateway_ref')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('posted');
            $table->string('status_note')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'external_ref']);
            $table->index(['status', 'fee_code']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_collections');
    }
};
