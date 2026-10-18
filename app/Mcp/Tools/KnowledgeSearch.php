<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\AgentEntityReference;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeIdentifier;
use App\Services\KnowledgeIdentityResolver;
use App\Support\KnowledgeName;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_search')]
#[Description('Fuzzy-search the internal knowledge base when knowledge_lookup misses or you need broader context — matches canonical names, aliases, summaries, entry state, and this agent\'s contextual titles. Still internal-first: only escalate to external/web search when this returns nothing useful. An exact title match for this agent ranks the canonical person ahead of body-text hits. Other agents\' titles are not searched.')]
class KnowledgeSearch extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, KnowledgeIdentityResolver $resolver): Response
    {
        $request->validate([
            'query' => 'required|string|max:200',
            'type' => 'nullable|string|in:'.implode(',', KnowledgeEntry::TYPES),
            'limit' => 'nullable|integer|min:1|max:25',
        ]);

        $query = trim($request->string('query')->toString());
        $normalized = KnowledgeName::normalize($query);
        $tokens = collect(preg_split('/\s+/', $normalized))->filter()->unique()->values();
        $limit = $request->integer('limit', 10);
        $agent = $this->agent();

        $agentRefIds = AgentEntityReference::query()
            ->where('agent_id', $agent->id)
            ->where(function ($q) use ($normalized, $tokens) {
                $q->where('normalized_value', $normalized);
                foreach ($tokens as $token) {
                    $q->orWhere('normalized_value', 'like', '%'.$token.'%');
                }
            })
            ->pluck('entry_id');

        $identifierIds = KnowledgeIdentifier::query()
            ->where(function ($q) use ($normalized, $tokens) {
                $q->where('normalized_value', $normalized);
                foreach ($tokens as $token) {
                    $q->orWhere('normalized_value', 'like', '%'.$token.'%');
                }
            })
            ->pluck('entry_id');

        $candidates = KnowledgeEntry::query()
            ->with('identifiers')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->get('type')))
            ->where(function ($q) use ($tokens, $agentRefIds, $identifierIds) {
                foreach ($tokens as $token) {
                    $q->orWhere('name_lower', 'like', '%'.$token.'%')
                        ->orWhere('aliases', 'like', '%'.$token.'%')
                        ->orWhere('summary', 'like', '%'.$token.'%')
                        ->orWhere('state', 'like', '%'.$token.'%')
                        ->orWhere('related', 'like', '%'.$token.'%');
                }
                if ($agentRefIds->isNotEmpty()) {
                    $q->orWhereIn('id', $agentRefIds);
                }
                if ($identifierIds->isNotEmpty()) {
                    $q->orWhereIn('id', $identifierIds);
                }
            })
            ->get();

        $exactAgentRefs = AgentEntityReference::query()
            ->where('agent_id', $agent->id)
            ->where('normalized_value', $normalized)
            ->pluck('entry_id')
            ->all();

        $results = $candidates
            ->map(function (KnowledgeEntry $entry) use ($resolver, $query, $tokens, $exactAgentRefs) {
                $canonical = $resolver->canonicalEntry($entry) ?? $entry;

                return [
                    'entry_id' => $canonical->id,
                    'name' => $canonical->name,
                    'type' => $canonical->type,
                    'summary' => $canonical->summary,
                    'score' => self::score($canonical, $query, $tokens, in_array($entry->id, $exactAgentRefs, true) || in_array($canonical->id, $exactAgentRefs, true)),
                ];
            })
            ->groupBy('entry_id')
            ->map(fn (Collection $group) => $group->sortByDesc('score')->first())
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        if ($results->isEmpty()) {
            return $this->jsonResponse([
                'found' => false,
                'query' => $query,
                'hint' => 'Nothing internal matched. An external lookup may be appropriate.',
            ]);
        }

        return $this->jsonResponse([
            'found' => true,
            'query' => $query,
            'results' => $results,
        ]);
    }

    /**
     * Rank a candidate: this agent's exact contextual title beats canonical
     * name beats aliases beat body text.
     *
     * @param  Collection<int, string>  $tokens
     */
    private static function score(
        KnowledgeEntry $canonical,
        string $query,
        $tokens,
        bool $exactAgentReference,
    ): int {
        $name = KnowledgeName::normalize($canonical->name);
        $score = 0;

        if ($exactAgentReference) {
            $score += 200;
        }

        if ($name === KnowledgeName::normalize($query)) {
            $score += 100;
        } elseif (str_contains($name, KnowledgeName::normalize($query))) {
            $score += 50;
        }

        foreach ($tokens as $token) {
            if (str_contains($name, $token)) {
                $score += 20;
            }

            foreach ($canonical->aliases as $alias) {
                if (str_contains(KnowledgeName::normalize($alias), $token)) {
                    $score += 15;

                    break;
                }
            }

            foreach ($canonical->identifiers as $identifier) {
                if (str_contains($identifier->normalized_value, $token)) {
                    $score += 15;

                    break;
                }
            }

            if (str_contains(KnowledgeName::normalize($canonical->summary), $token)) {
                $score += 5;
            }

            if (str_contains(KnowledgeName::normalize($canonical->state), $token)) {
                $score += 2;
            }
        }

        return $score;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Free-text search across names, aliases, this agent\'s contextual titles, summaries, and entry state.')->required(),
            'type' => $schema->string()->enum(KnowledgeEntry::TYPES)->description('Narrow to one entry type.'),
            'limit' => $schema->integer()->description('Max results (default 10, max 25).'),
            ...$this->personaSchema($schema),
        ];
    }
}
