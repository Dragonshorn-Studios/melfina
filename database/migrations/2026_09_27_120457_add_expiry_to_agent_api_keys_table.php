<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expiring scoped keys: enrollment mints 24 h credentials that must
     * fail closed after their TTL. `enrollment_id` ties a minted key to
     * the enrollment that produced it so revoking acceptance kills every
     * key minted from it.
     */
    public function up(): void
    {
        Schema::table('agent_api_keys', function (Blueprint $table) {
            $table->foreignId('enrollment_id')->nullable()
                ->constrained('agent_enrollments')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_api_keys', function (Blueprint $table) {
            $table->dropConstrainedForeignId('enrollment_id');
            $table->dropColumn('expires_at');
        });
    }
};
