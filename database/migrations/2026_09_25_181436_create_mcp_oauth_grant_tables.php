<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mcp_pending_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('client_id');
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
        });

        Schema::create('mcp_token_agents', function (Blueprint $table): void {
            $table->id();
            $table->string('token_id')->unique();
            $table->string('client_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_token_agents');
        Schema::dropIfExists('mcp_pending_grants');
    }
};
