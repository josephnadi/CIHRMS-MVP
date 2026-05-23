<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents v2 — Phase 1.
 *
 * Read-only sharing distinct from the existing sequential `document_routes`
 * workflow. A share gives a user, a department, or the whole organization
 * view-only access without putting the document on anyone's action queue.
 *
 * audience_type ∈ {user, department, organization}.
 * audience_id is the users.id / departments.id; NULL when audience_type=organization.
 *
 * Confidentiality guard (enforced in DocumentShareService): documents with
 * confidentiality ∈ {confidential, restricted} cannot be shared with
 * department or organization audiences — backend returns 422.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('audience_type', 20);    // user | department | organization
            $table->unsignedBigInteger('audience_id')->nullable();
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Each audience can only be granted once per document.
            $table->unique(['document_id', 'audience_type', 'audience_id'], 'document_shares_unique');
            $table->index(['audience_type', 'audience_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_shares');
    }
};
