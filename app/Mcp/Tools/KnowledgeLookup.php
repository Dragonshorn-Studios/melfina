<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\KnowledgeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_lookup')]
#[Description('Look up a named internal thing — a project, service, codename, decision, convention, runbook, person, or concept — in the shared knowledge base. Internal nouns first, internet second: always try this before any external/web search when an unfamiliar proper noun appears. Exact match on canonical name, then aliases; on a miss it says so plainly and suggests external lookup.')]
class KnowledgeLookup extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'query' => 'required|string|max:200',
            'history' => 'nullable|boolean',
        ]);

        $query = $request->string('query')->toString();
        $entry = KnowledgeEntry::lookup($query);

        if ($entry === null) {
            return $this->jsonResponse([
                'found' => false,
                'query' => $query,
                'hint' => 'No internal knowledge entry matches. Try knowledge_search for broader internal matches; if nothing turns up, an external lookup may be appropriate.',
            ]);
        }

        $related = $entry->resolvedRelated();

        $payload = [
            'found' => true,
            'name' => $entry->name,
            'type' => $entry->type,
            'aliases' => $entry->aliases,
            'summary' => $entry->summary,
            'state' => $entry->state,
            'related' => $related['entries'],
            'unresolved_related' => $related['unresolved'],
            'source' => $entry->source,
            'version' => $entry->version,
            'updated_by' => $entry->updated_by,
            'verified_at' => $entry->verified_at?->toIso8601String(),
            'updated_at' => $entry->updated_at->toIso8601String(),
        ];

        if ($request->boolean('history')) {
            $payload['history'] = $entry->versions()
                ->orderByDesc('version')
                ->limit(20)
                ->get(['version', 'author', 'created_at'])
                ->map(fn ($v) => [
                    'version' => $v->version,
                    'author' => $v->author,
                    'at' => $v->created_at->toIso8601String(),
                ])
                ->values();
        }

        return $this->jsonResponse($payload);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Canonical name or alias to look up — e.g. an internal project codename, service, person, or convention.')->required(),
            'history' => $schema->boolean()->description('Also return the version history (who changed what, when).'),
            ...$this->personaSchema($schema),
        ];
    }
}
