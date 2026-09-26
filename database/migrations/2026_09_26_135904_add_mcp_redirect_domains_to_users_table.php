<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner-managed OAuth redirect origins (scheme://host[:port]) merged into
     * config('mcp.redirect_domains') by MergeMcpRedirectDomains — the console
     * equivalent of editing MCP_REDIRECT_DOMAINS.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('mcp_redirect_domains')->nullable()->after('mcp_key_issued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mcp_redirect_domains');
        });
    }
};
