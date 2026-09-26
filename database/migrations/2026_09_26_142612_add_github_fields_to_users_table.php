<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner's GitHub credential for publishing the fleet plugin package to
     * a repo MiniMax-style importers can read. github_token is Laravel-
     * encrypted at rest (model 'encrypted' cast); github_repo is the
     * chosen "owner/name" target.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('github_token')->nullable()->after('mcp_redirect_domains');
            $table->string('github_repo')->nullable()->after('github_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['github_token', 'github_repo']);
        });
    }
};
