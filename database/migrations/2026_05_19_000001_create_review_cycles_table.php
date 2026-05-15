<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                  // e.g. "H1 2026", "Q3 2026"
            $table->string('cadence')->default('half_year');         // annual / half_year / quarterly / probation
            $table->date('starts_at');
            $table->date('ends_at');
            $table->date('self_review_due')->nullable();
            $table->date('peer_review_due')->nullable();
            $table->date('manager_review_due')->nullable();
            $table->string('status')->default('draft');              // ReviewCycleStatus
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_cycles');
    }
};
