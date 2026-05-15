<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider');                       // zoho_crm, ms_graph, google, whatsapp_cloud, slack, ms_teams, docusign
            $table->string('capability');                     // crm, files, spreadsheet, messaging, calendar, esign, identity
            $table->string('display_name');
            $table->string('logo')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'capability']);
            $table->index(['capability', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
