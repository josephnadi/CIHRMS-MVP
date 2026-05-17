<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single Sign-On (Phase 4 / WS19).
 *
 *  - identity_providers: registered upstream IdPs (NITA IDM, ghana.gov,
 *    Azure AD, Auth0, Keycloak, etc.). Per-provider config is JSON so we
 *    don't need a new schema per provider type.
 *  - user_identity_links: many-to-many between Users and IdPs, keyed by
 *    the IdP's stable `sub` claim. One user can have multiple links so
 *    they can sign in via NITA on a govt workstation OR Microsoft Entra
 *    from a personal device.
 *  - sso_login_attempts: tamper-evident audit log of every SSO attempt,
 *    successful or not. Always recorded — failures are signal too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();              // 'nita', 'ghana-gov', 'azure-cihrms'
            $table->string('name');                            // human display
            $table->string('type', 16);                        // oidc | saml
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_provision')->default(false); // JIT-create users on first login
            $table->string('default_role', 32)->default('employee');
            $table->json('config');                            // provider-specific (issuer, endpoints, certs)
            $table->json('claim_mapping')->nullable();          // e.g. {"email": "preferred_username", "name": "name"}
            $table->json('allowed_email_domains')->nullable(); // ['cihrm.gov.gh', 'mofep.gov.gh']
            $table->string('button_label')->nullable();        // "Sign in with NITA"
            $table->string('button_icon', 64)->nullable();     // material icon name
            $table->unsignedSmallInteger('display_order')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'display_order']);
        });

        Schema::create('user_identity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('identity_providers')->cascadeOnDelete();
            $table->string('external_subject_id', 255);        // IdP's stable `sub` claim
            $table->string('external_email')->nullable();
            $table->json('last_claims')->nullable();           // snapshot of claims at last login
            $table->timestamp('linked_at');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'external_subject_id'], 'uil_provider_sub_unique');
            $table->index(['user_id', 'provider_id']);
        });

        Schema::create('sso_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->nullable()->constrained('identity_providers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_subject_id', 255)->nullable();
            $table->string('external_email')->nullable();
            $table->string('outcome', 32);                     // matches SsoLoginOutcome
            $table->text('error')->nullable();
            $table->json('claims_snapshot')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_login_attempts');
        Schema::dropIfExists('user_identity_links');
        Schema::dropIfExists('identity_providers');
    }
};
