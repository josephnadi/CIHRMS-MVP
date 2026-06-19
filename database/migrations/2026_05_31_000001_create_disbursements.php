<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disbursement record — one per (payroll_line, channel) attempt. Lets us
 * retry a failed MoMo push without losing the audit trail of the prior
 * attempt, and lets a single payroll run mix GhIPSS + MoMo + Cash recipients.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add per-employee disbursement preference
        Schema::table('employees', function (Blueprint $table) {
            $table->string('disbursement_channel', 32)->default('ghipss_ach')->after('bank_account');
            $table->string('mobile_money_number', 32)->nullable()->after('disbursement_channel');
            $table->string('mobile_money_network', 16)->nullable()->after('mobile_money_number');
        });

        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('final_settlement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 32);
            $table->string('status', 16)->default('pending');

            $table->decimal('gross_amount', 14, 2);         // the net-pay being sent
            $table->decimal('e_levy', 14, 2)->default(0);   // 1.5% E-Levy on MoMo channels
            $table->decimal('provider_fee', 14, 2)->default(0);
            $table->decimal('net_to_recipient', 14, 2);     // gross_amount - e_levy - provider_fee

            $table->string('beneficiary_account', 64)->nullable();   // bank account OR MoMo number
            $table->string('beneficiary_name')->nullable();
            $table->string('provider_reference', 128)->nullable();   // ID from MTN / VF / AT
            $table->json('provider_response')->nullable();           // last raw response

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['payroll_run_id', 'channel', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['disbursement_channel', 'mobile_money_number', 'mobile_money_network']);
        });
    }
};
