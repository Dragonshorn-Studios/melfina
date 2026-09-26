<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scoped per-agent API keys for the HTTP tool fallback
     * (POST /api/tools/{tool}) — least-privilege credentials for agent
     * runtimes that cannot install an MCP server. Only the hash is stored.
     */
    public function up(): void
    {
        Schema::create('agent_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_hash');
            $table->json('scopes');
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
        Schema::dropIfExists('agent_api_keys');
    }
};
