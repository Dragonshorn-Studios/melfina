<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named fleet keys: each shared MCP credential is its own row so one
     * leaked/retired key can be revoked without rotating the rest.
     * Existing installs keep working — the owner's legacy single hash is
     * migrated into a row named 'default'.
     */
    public function up(): void
    {
        Schema::create('mcp_fleet_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_hash');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('key_hash');
        });

        // Carry the legacy single-key hash forward so existing fleet keys
        // keep resolving after the move to named keys — then clear the
        // column, otherwise the legacy fallback in isMcpKey() would keep
        // accepting a key whose 'default' row was revoked.
        DB::table('users')
            ->where('is_owner', true)
            ->whereNotNull('mcp_key_hash')
            ->get()
            ->each(function (object $user): void {
                DB::table('mcp_fleet_keys')->insert([
                    'user_id' => $user->id,
                    'name' => 'default',
                    'key_hash' => $user->mcp_key_hash,
                    'created_at' => $user->mcp_key_issued_at ?? now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('users')->where('is_owner', true)->update(['mcp_key_hash' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_fleet_keys');
    }
};
