<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->decimal('refund_amount', 18, 2)->nullable()->after('refunded_at');
            $table->string('refund_reason', 500)->nullable()->after('refund_amount');
            $table->string('refund_paystack_ref', 100)->nullable()->after('refund_reason');
            $table->timestamp('refund_settled_at')->nullable()->after('refund_paystack_ref');
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete()->after('refund_settled_at');

            $table->index('refund_paystack_ref');
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropForeign(['refunded_by']);
            $table->dropIndex(['refund_paystack_ref']);
            $table->dropColumn([
                'refunded_at', 'refund_amount', 'refund_reason',
                'refund_paystack_ref', 'refund_settled_at', 'refunded_by',
            ]);
        });
    }
};
