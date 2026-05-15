<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('progress_pct', 5, 2)->default(0);       // 0..100 — derived snapshot at the time of check-in
            $table->decimal('current_value', 14, 2)->nullable();     // optional, mirrors goal.current_value
            $table->text('narrative')->nullable();                   // what was done, blockers, asks
            $table->string('mood', 20)->nullable();                  // green / amber / red
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->index(['goal_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_checkins');
    }
};
