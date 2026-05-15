<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag', 40)->unique();
            $table->string('name', 120);
            $table->string('category', 16);
            $table->string('serial_number', 80)->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->char('currency', 3)->default('GHS');
            $table->string('supplier', 120)->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->string('current_status', 16)->default('in_stock');
            $table->unsignedBigInteger('current_assignment_id')->nullable();
            $table->string('location', 120)->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('category');
            $table->index('current_status');
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->date('due_back_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('condition_on_return', 12)->nullable();
            $table->text('notes')->nullable();
            $table->string('signed_handover_path', 255)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('asset_id');
            $table->index('employee_id');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('current_assignment_id')
                ->references('id')->on('asset_assignments')
                ->nullOnDelete();
        });

        Schema::create('asset_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);
            $table->string('status', 12)->default('open');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('vendor', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('asset_id');
            $table->index('status');
        });

        Schema::create('asset_depreciation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('as_of_date');
            $table->decimal('book_value', 12, 2);
            $table->string('method', 20)->default('straight_line');
            $table->unsignedSmallInteger('useful_life_years');
            $table->decimal('salvage_value', 12, 2);
            $table->timestamps();

            $table->unique(['asset_id', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_snapshots');
        Schema::dropIfExists('asset_maintenance');
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['current_assignment_id']);
        });
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
    }
};
