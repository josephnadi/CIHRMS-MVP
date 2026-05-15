<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated per-employee recurring allowances and deductions.
 * The payroll engine reads these for each run rather than embedding amounts
 * on the employee row, so historical runs stay reproducible after changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->boolean('is_taxable')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('label');
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('percentage', 6, 4)->nullable(); // 0.0500 = 5% of gross; mutually exclusive with amount
            $table->decimal('cap_balance', 14, 2)->nullable(); // loan outstanding balance
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deductions');
        Schema::dropIfExists('allowances');
    }
};
