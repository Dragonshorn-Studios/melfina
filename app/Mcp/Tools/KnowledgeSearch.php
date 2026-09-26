<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\KnowledgeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_search')]
#[Description('Fuzzy-search the internal knowledge base when knowledge_lookup misses or you need broader context — matches canonical names, aliases, summaries, and entry state. Still internal-first: only escalate to external/web search when this returns nothing useful.')]
class KnowledgeSearch extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'query' => 'required|string|max:200',
            'type' => 'nullable|string|in:'.implode(',', KnowledgeEntry::TYPES),
            'limit' => 'nullable|integer|min:1|max:25',
        ]);

        $query = trim($request->string('query')->toString());
        $tokens = collect(preg_split('/\s+/', Str::lower($query)))->filter()->unique()->values();
        $limit = $request->integer('limit', 10);

        $candidates = KnowledgeEntry::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->get('type')))
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $q->orWhere('name_lower', 'like', '%'.$token.'%')
                        ->orWhere('aliases', 'like', '%'.$token.'%')
                        ->orWhere('summary', 'like', '%'.$token.'%')
                        ->orWhere('state', 'like', '%'.$token.'%')
                        ->orWhere('related', 'like', '%'.$token.'%');
                }
            })
            ->get();

        $results = $candidates
            ->map(fn (KnowledgeEntry $entry) => [
                'name' => $entry->name,
                'type' => $entry->type,
                'summary' => $entry->summary,
                'score' => self::score($entry, $query, $tokens),
            ])
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
     * Rank a candidate: exact/canonical matches beat aliases beat body
     * text, and every distinct query token that hits adds weight.
     *
     * @param  Collection<int, string>  $tokens
     */
    private static function score(KnowledgeEntry $entry, string $query, $tokens): int
    {
        $name = Str::lower($entry->name);
        $score = 0;

        if ($name === Str::lower($query)) {
            $score += 100;
        } elseif (str_contains($name, Str::lower($query))) {
            $score += 50;
        }

        foreach ($tokens as $token) {
            if (str_contains($name, $token)) {
                $score += 20;
            }

            foreach ($entry->aliases as $alias) {
                if (str_contains(Str::lower($alias), $token)) {
                    $score += 15;

                    break;
                }
            }

            if (str_contains(Str::lower($entry->summary), $token)) {
                $score += 5;
            }

            if (str_contains(Str::lower($entry->state), $token)) {
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
            'query' => $schema->string()->description('Free-text search across names, aliases, summaries, and entry state.')->required(),
            'type' => $schema->string()->enum(KnowledgeEntry::TYPES)->description('Narrow to one entry type.'),
            'limit' => $schema->integer()->description('Max results (default 10, max 25).'),
            ...$this->personaSchema($schema),
        ];
    }
}
