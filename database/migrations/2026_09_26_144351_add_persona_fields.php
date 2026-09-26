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
        Schema::table('agents', function (Blueprint $table): void {
            // Short blurb other agents see via list_agents — "who is this imp".
            $table->string('description')->nullable()->after('status');
            // Longer self-authored persona the agent can maintain.
            $table->text('persona')->nullable()->after('description');
            // Ordered memory entries the agent appends over time.
            $table->json('memories')->nullable()->after('persona');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Owner-authored persona shared with every agent on
            // read_instructions — who the human is, how to address them.
            $table->text('operator_persona')->nullable()->after('github_repo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn(['description', 'persona', 'memories']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('operator_persona');
        });
    }
};
