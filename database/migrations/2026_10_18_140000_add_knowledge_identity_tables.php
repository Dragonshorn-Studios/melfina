<?php

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeIdentifierKind;
use App\Support\KnowledgeName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive identity tables. JSON `aliases` stays as a compatibility
     * projection; this migration copies existing alias values into
     * knowledge_identifiers without guessing which names are the same person.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('operator_entry_id')
                ->nullable()
                ->after('operator_persona')
                ->constrained('knowledge_entries')
                ->restrictOnDelete();
        });

        Schema::create('knowledge_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->string('value');
            $table->string('normalized_value');
            $table->string('kind');
            $table->string('author')->default('owner');
            $table->timestamps();

            $table->unique(['entry_id', 'normalized_value']);
            $table->index('normalized_value');
        });

        Schema::create('agent_entity_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->string('value');
            $table->string('normalized_value');
            $table->string('kind');
            $table->string('author')->default('owner');
            $table->timestamps();

            $table->unique(['agent_id', 'normalized_value']);
            $table->index(['normalized_value', 'agent_id']);
        });

        Schema::create('knowledge_entry_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_entry_id')->unique()->constrained('knowledge_entries')->restrictOnDelete();
            $table->foreignId('to_entry_id')->constrained('knowledge_entries')->restrictOnDelete();
            $table->string('author')->default('owner');
            $table->timestamps();

            $table->index('to_entry_id');
        });

        Schema::create('agent_addressing_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->foreignId('person_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->string('context')->default('default');
            $table->string('form');
            $table->string('author')->default('owner');
            $table->timestamps();

            $table->unique(['agent_id', 'person_entry_id', 'context']);
        });

        KnowledgeEntry::query()->orderBy('id')->each(function (KnowledgeEntry $entry): void {
            $seen = [];

            foreach ($entry->aliases ?? [] as $alias) {
                if (! is_string($alias)) {
                    continue;
                }

                $value = trim($alias);
                $normalized = KnowledgeName::normalize($value);

                if ($value === '' || $normalized === '' || isset($seen[$normalized])) {
                    continue;
                }

                if ($normalized === $entry->name_lower) {
                    continue;
                }

                $seen[$normalized] = true;

                $entry->identifiers()->create([
                    'value' => $value,
                    'normalized_value' => $normalized,
                    'kind' => KnowledgeIdentifierKind::Alias,
                    'author' => $entry->updated_by !== '' ? $entry->updated_by : 'owner',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_addressing_preferences');
        Schema::dropIfExists('knowledge_entry_redirects');
        Schema::dropIfExists('agent_entity_references');
        Schema::dropIfExists('knowledge_identifiers');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operator_entry_id');
        });
    }
};
