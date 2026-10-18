<?php

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgePredicate;
use App\Services\KnowledgeGraph;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->foreignId('to_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->string('predicate');
            $table->string('status')->default('active');
            $table->unsignedInteger('confidence')->default(0);
            $table->unsignedInteger('observation_count')->default(0);
            $table->boolean('disputed')->default(false);
            $table->boolean('permanent')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['from_entry_id', 'to_entry_id', 'predicate']);
            $table->index(['to_entry_id', 'predicate']);
        });

        Schema::create('knowledge_observations', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->foreignId('entry_id')->nullable()->constrained('knowledge_entries')->cascadeOnDelete();
            $table->foreignId('link_id')->nullable()->constrained('knowledge_links')->cascadeOnDelete();
            $table->string('author');
            $table->string('author_kind');
            $table->json('payload');
            $table->string('source')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['link_id', 'created_at']);
            $table->index(['entry_id', 'created_at']);
        });

        $graph = app(KnowledgeGraph::class);

        KnowledgeEntry::query()->each(function (KnowledgeEntry $entry) use ($graph): void {
            foreach ($entry->related ?? [] as $name) {
                if (! is_string($name) || trim($name) === '') {
                    continue;
                }

                $kind = $entry->updated_by === 'owner'
                    ? KnowledgeAuthorKind::Owner
                    : KnowledgeAuthorKind::Agent;

                $graph->connect(
                    author: $entry->updated_by !== '' ? $entry->updated_by : 'owner',
                    authorKind: $kind,
                    from: $entry->name,
                    to: $name,
                    predicate: KnowledgePredicate::RelatedTo,
                    source: 'migrated from related',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_observations');
        Schema::dropIfExists('knowledge_links');
    }
};
