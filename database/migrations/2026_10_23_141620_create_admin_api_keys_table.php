<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin API keys (`melfa_…`): the owner-minted credential a fleet
     * system (Yukihime or another harness) presents to /api/admin to
     * provision and inspect brains. Each is its own row so a leaked key
     * can be revoked alone — same shape as mcp_fleet_keys, plus a
     * permissions allowlist so a consumer can be least-privileged. Only
     * the hash is stored.
     */
    public function up(): void
    {
        Schema::create('admin_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_hash');
            $table->json('permissions');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('key_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_api_keys');
    }
};
