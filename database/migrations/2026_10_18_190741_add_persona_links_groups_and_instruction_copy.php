<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->text('instruction_copy')->nullable()->after('state');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('main_person_entry_id')
                ->nullable()
                ->after('persona')
                ->constrained('knowledge_entries')
                ->nullOnDelete();
        });

        Schema::create('knowledge_persona_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_low_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->foreignId('entry_high_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['entry_low_id', 'entry_high_id']);
        });

        Schema::create('agent_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('preamble')->nullable();
            $table->foreignId('main_person_entry_id')
                ->nullable()
                ->constrained('knowledge_entries')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('agent_group_agent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['agent_group_id', 'agent_id']);
        });

        Schema::create('shared_group_acl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_space_id')->constrained('shared_spaces')->cascadeOnDelete();
            $table->foreignId('agent_group_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 16);
            $table->timestamps();

            $table->unique(['shared_space_id', 'agent_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_group_acl');
        Schema::dropIfExists('agent_group_agent');
        Schema::dropIfExists('agent_groups');
        Schema::dropIfExists('knowledge_persona_links');

        Schema::table('agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('main_person_entry_id');
        });

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('instruction_copy');
        });
    }
};
