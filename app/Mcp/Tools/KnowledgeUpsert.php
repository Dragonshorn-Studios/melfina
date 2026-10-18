<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Services\KnowledgeEntryWriter;
use App\Services\KnowledgeIdentityContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_upsert')]
#[Description('Create or update a canonical knowledge entry — a project, service, decision, convention, runbook, person, or concept the install should know by name. `entry` is a target reference (canonical name, alias, persona name, or this agent\'s contextual title), not a rename: updating through an alias keeps the canonical name. Every write records a versioned snapshot with you as author. Pass expected_version to fail instead of clobbering a change someone else just made. Pass entry_id to pin the target; id and entry must agree.')]
class KnowledgeUpsert extends Tool
{
    use ResolvesAgent;

    /**
     * Updates allowed per minute per agent. Each knowledge_upsert, each
     * dual-written related link, and each knowledge_connect (plus its
     * named-event side links) counts as one unit.
     */
    public const WRITE_LIMIT_PER_MINUTE = 30;

    /**
     * Reserve `$cost` units of the shared knowledge write budget, or
     * return an error response when the agent is out of quota.
     */
    public static function consumeWriteQuota(int $agentId, int $cost): ?Response
    {
        $cost = max(1, $cost);
        $key = 'mcp-knowledge:'.$agentId;

        if (RateLimiter::remaining($key, self::WRITE_LIMIT_PER_MINUTE) < $cost) {
            return Response::error('Knowledge write rate limit exceeded. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        RateLimiter::increment($key, 60, $cost);

        return null;
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, KnowledgeEntryWriter $writer): Response
    {
        $request->validate([
            'entry' => 'required|string|max:200',
            'entry_id' => 'nullable|integer|min:1',
            'type' => ['nullable', 'string', Rule::in(KnowledgeEntry::TYPES)],
            'aliases' => 'nullable|array|max:20',
            'aliases.*' => 'string|max:200',
            'summary' => 'nullable|string|max:500',
            'state' => 'nullable|string|max:20000',
            'related' => 'nullable|array|max:20',
            'related.*' => 'string|max:200',
            'source' => 'nullable|string|max:500',
            'verified' => 'nullable|boolean',
            'expected_version' => 'nullable|integer|min:1',
        ]);

        $agent = $this->agent();
        $relatedNames = $request->has('related')
            ? array_values($request->get('related') ?? [])
            : null;

        if ($limited = self::consumeWriteQuota($agent->id, 1 + count($relatedNames ?? []))) {
            return $limited;
        }

        $fields = [];
        foreach (['summary', 'state', 'source'] as $field) {
            if ($request->has($field)) {
                $fields[$field] = $request->get($field);
            }
        }

        $result = $writer->upsert(
            context: KnowledgeIdentityContext::forAgent($agent),
            author: $agent->name,
            authorKind: KnowledgeAuthorKind::Agent,
            entryName: trim($request->string('entry')->toString()),
            entryId: $request->filled('entry_id') ? $request->integer('entry_id') : null,
            type: $request->filled('type') ? $request->string('type')->toString() : null,
            aliases: $request->has('aliases') ? array_values($request->get('aliases') ?? []) : null,
            related: $relatedNames,
            fields: $fields,
            expectedVersion: $request->filled('expected_version') ? $request->integer('expected_version') : null,
            verified: $request->boolean('verified'),
        );

        if ($result->conflict) {
            return $this->jsonResponse([
                'updated' => false,
                'conflict' => true,
                'entry' => $result->entry?->name,
                'entry_id' => $result->entry?->id,
                'current_version' => $result->currentVersion,
                'hint' => 'The entry changed since you read it — re-read it with knowledge_lookup and merge before writing.',
            ]);
        }

        if (! $result->ok) {
            if ($result->code === 'ambiguous_reference') {
                return $this->jsonResponse([
                    'updated' => false,
                    'ambiguous' => true,
                    'code' => 'ambiguous_reference',
                    'candidates' => $result->candidates,
                    'hint' => $result->error,
                ]);
            }

            return Response::error($result->error ?? 'Could not write the entry.');
        }

        return $this->jsonResponse([
            'updated' => true,
            'created' => $result->created,
            'entry' => $result->entry->name,
            'entry_id' => $result->entry->id,
            'version' => $result->entry->version,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'entry' => $schema->string()->description('Target reference — canonical name, alias, persona name, or this agent\'s contextual title. Not a rename; the canonical name stays. (`name` stays reserved for your persona.)')->required(),
            'entry_id' => $schema->integer()->description('Stable entity id. When supplied with entry, both must name the same canonical entity.'),
            'type' => $schema->string()->enum(KnowledgeEntry::TYPES)->description('Entry type. Required when creating.'),
            'aliases' => $schema->array()->items($schema->string())->description('Other global names this entry answers to (ordinary aliases). Does not add or remove scoped agent titles or persona/handle identifiers.'),
            'summary' => $schema->string()->description('One-line "what is this" — shown in search results.'),
            'state' => $schema->string()->description('Current state and important constraints — the durable facts.'),
            'related' => $schema->array()->items($schema->string())->description('Canonical names of related entries.'),
            'source' => $schema->string()->description('Provenance — where this knowledge came from (doc, ticket, chat).'),
            'verified' => $schema->boolean()->description('Mark the entry as freshly verified — bumps verified_at.'),
            'expected_version' => $schema->integer()->description('Optimistic-lock guard: only write if the entry is still at this version.'),
            ...$this->personaSchema($schema),
        ];
    }
}
