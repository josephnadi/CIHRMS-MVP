<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->foreignId('gl_account_id')->constrained('gl_accounts');
            $table->string('domain', 50)->index();
            $table->string('description', 255)->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_accounts');
    }
};
