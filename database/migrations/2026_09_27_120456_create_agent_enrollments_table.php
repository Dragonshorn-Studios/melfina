<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent self-enrollment requests: a runtime with no secret storage
     * reports remote_id + name over /api/enroll; the owner accepts (with
     * a scope allowlist, recorded here so re-minting is deterministic) or
     * revokes. Accepting binds the row to an Agent; the minted
     * credentials live on agent_api_keys.
     */
    public function up(): void
    {
        Schema::create('agent_enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('remote_id');
            $table->string('name');
            $table->string('status')->default('pending');
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->json('scopes')->nullable();
            $table->timestamps();

            $table->unique('remote_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_enrollments');
    }
};
