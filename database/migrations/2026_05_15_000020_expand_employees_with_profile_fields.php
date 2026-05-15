<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Personal
            $table->string('gender', 16)->nullable()->after('phone');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('national_id', 64)->nullable()->after('date_of_birth');
            $table->string('address')->nullable()->after('national_id');
            $table->string('avatar_path')->nullable()->after('address');

            // Emergency contact
            $table->string('emergency_contact_name')->nullable()->after('avatar_path');
            $table->string('emergency_contact_phone', 32)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship', 64)->nullable()->after('emergency_contact_phone');

            // Compensation / chain of command
            $table->string('bank_name')->nullable()->after('emergency_contact_relationship');
            $table->string('bank_account', 64)->nullable()->after('bank_name');
            $table->decimal('salary', 12, 2)->nullable()->after('bank_account');
            $table->foreignId('manager_id')->nullable()->after('salary')
                ->constrained('employees')->nullOnDelete();

            $table->index('manager_id');
        });

        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('level', 32)->nullable(); // beginner|intermediate|expert
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn([
                'gender', 'date_of_birth', 'national_id', 'address', 'avatar_path',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
                'bank_name', 'bank_account', 'salary', 'manager_id',
            ]);
        });
    }
};
