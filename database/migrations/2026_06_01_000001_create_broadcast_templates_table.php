<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broadcast_templates', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('audience_type', 64);
            $t->text('sms_body')->nullable();
            $t->string('mail_subject', 150)->nullable();
            $t->text('mail_body')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();

            $t->index(['audience_type', 'is_active']);
        });
    }

    public function down(): void { Schema::dropIfExists('broadcast_templates'); }
};
