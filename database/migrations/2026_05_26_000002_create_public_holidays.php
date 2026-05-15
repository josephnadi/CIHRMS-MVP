<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ghana statutory public holidays. Used by AttendanceService to mark days
 * as `holiday` (excused), and by OvertimeCalculator to apply the 2× rate
 * for work performed on a public holiday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 8)->default('GH');
            $table->date('holiday_date');
            $table->string('name');
            $table->boolean('is_observed')->default(true); // Sunday holidays may be observed on Monday
            $table->date('observed_date')->nullable();
            $table->timestamps();

            $table->unique(['jurisdiction', 'holiday_date']);
            $table->index(['jurisdiction', 'observed_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
