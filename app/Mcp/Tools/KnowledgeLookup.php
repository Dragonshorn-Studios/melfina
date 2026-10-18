<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\KnowledgeIdentityStatus;
use App\Services\KnowledgeGraph;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_lookup')]
#[Description('Look up a named internal thing — a project, service, codename, decision, convention, runbook, person, event, or concept — in the shared knowledge base. Returns the wiki body plus a typed 1-hop neighborhood (predicate, peer, confidence). Names, aliases, persona names, and this agent\'s contextual titles resolve to the same canonical entity id. Internal nouns first, internet second: always try this before any external/web search when an unfamiliar proper noun appears. On a miss it says so plainly and suggests external lookup. Ambiguous names are not a miss — they return code ambiguous_reference and do not suggest the internet.')]
class KnowledgeLookup extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, KnowledgeGraph $graph, KnowledgeIdentityResolver $resolver): Response
    {
        $request->validate([
            'query' => 'required|string|max:200',
            'entry_id' => 'nullable|integer|min:1',
            'ref' => 'nullable|string|in:operator',
            'history' => 'nullable|boolean',
            'hops' => 'nullable|integer|min:1|max:2',
            'min_confidence' => 'nullable|integer|min:0|max:1000',
        ]);

        $query = $request->string('query')->toString();
        $entryId = $request->filled('entry_id') ? $request->integer('entry_id') : null;
        $ref = $request->filled('ref') ? $request->string('ref')->toString() : null;
        $resolved = $resolver->resolve(
            KnowledgeIdentityContext::forAgent($this->agent()),
            query: $query,
            entryId: $entryId,
            ref: $ref,
        );

        if ($resolved->status === KnowledgeIdentityStatus::Ambiguous) {
            return $this->jsonResponse([
                'found' => false,
                'ambiguous' => true,
                'code' => 'ambiguous_reference',
                'query' => $query,
                'candidates' => $resolved->candidates,
                'hint' => $resolved->error,
            ]);
        }

        if (in_array($resolved->status, [
            KnowledgeIdentityStatus::Inconsistent,
            KnowledgeIdentityStatus::Conflict,
        ], true)) {
            return $this->jsonResponse([
                'found' => false,
                'code' => $resolved->code,
                'query' => $query,
                'hint' => $resolved->error,
            ]);
        }

        if (! $resolved->found()) {
            return $this->jsonResponse([
                'found' => false,
                'query' => $query,
                'hint' => 'No internal knowledge entry matches. Try knowledge_search for broader internal matches; if nothing turns up, an external lookup may be appropriate.',
            ]);
        }

        $entry = $resolved->entry;
        $related = $entry->resolvedRelated();
        $minConfidence = $request->integer('min_confidence', 0);
        $hops = $request->integer('hops', 1);
        $links = $graph->neighborhood($entry, $minConfidence);

        $payload = [
            'found' => true,
            'entry_id' => $entry->id,
            'name' => $entry->name,
            'type' => $entry->type,
            'aliases' => $entry->aliases,
            'summary' => $entry->summary,
            'state' => $entry->state,
            'related' => $related['entries'],
            'unresolved_related' => $related['unresolved'],
            'links' => $links,
            'source' => $entry->source,
            'version' => $entry->version,
            'updated_by' => $entry->updated_by,
            'verified_at' => $entry->verified_at?->toIso8601String(),
            'updated_at' => $entry->updated_at->toIso8601String(),
            'resolution' => $resolved->resolutionPayload(),
        ];

        if ($hops === 2) {
            $payload['walk'] = $graph->walk($entry, hops: 2, minConfidence: $minConfidence);
        }

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
            $payload['observations'] = $entry->observations()
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'kind' => $o->kind->value,
                    'author' => $o->author,
                    'source' => $o->source,
                    'at' => $o->created_at->toIso8601String(),
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
            'query' => $schema->string()->description('Canonical name, alias, persona name, this agent\'s contextual title, or the reserved word operator. Lookup returns the canonical entity; it does not change how you address anyone.')->required(),
            'entry_id' => $schema->integer()->description('Stable entity id. When supplied with query, both must name the same canonical entity.'),
            'ref' => $schema->string()->enum(['operator'])->description('Structured installation role. Prefer ref=operator over relying on a person named Operator.'),
            'history' => $schema->boolean()->description('Also return wiki version history and recent graph observations.'),
            'hops' => $schema->integer()->description('Neighborhood depth. 1 (default) is the typed 1-hop links payload; 2 also returns a bounded walk.'),
            'min_confidence' => $schema->integer()->description('Hide links below this milli-confidence (0–1000). Default 0 so weak links stay visible.'),
            ...$this->personaSchema($schema),
        ];
    }
}
