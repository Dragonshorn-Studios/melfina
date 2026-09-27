<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named enrollment keys (`melfe_…`): the shared "global secret" an
     * agent runtime presents to POST /api/enroll to report in. Each is
     * its own row so a leaked key can be revoked without rotating the
     * rest — same shape as mcp_fleet_keys. Only the hash is stored.
     */
    public function up(): void
    {
        Schema::create('agent_enroll_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_hash');
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
        Schema::dropIfExists('agent_enroll_keys');
    }
};
