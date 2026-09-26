<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\KnowledgeEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_upsert')]
#[Description('Create or update a canonical knowledge entry — a project, service, decision, convention, runbook, person, or concept the install should know by name. Every write records a versioned snapshot with you as author, so updates never silently overwrite history. Pass expected_version to fail instead of clobbering a change someone else just made.')]
class KnowledgeUpsert extends Tool
{
    use ResolvesAgent;

    /**
     * Updates allowed per minute per agent.
     */
    public const WRITE_LIMIT_PER_MINUTE = 30;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'entry' => 'required|string|max:200',
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

        $rateKey = 'mcp-knowledge:'.$agent->id;

        if (RateLimiter::tooManyAttempts($rateKey, self::WRITE_LIMIT_PER_MINUTE)) {
            return Response::error('Knowledge write rate limit exceeded. Try again in '.RateLimiter::availableIn($rateKey).' seconds.');
        }

        $name = trim($request->string('entry')->toString());
        $entry = KnowledgeEntry::query()
            ->where('name_lower', Str::lower($name))
            ->first();

        if ($entry === null && ! $request->filled('type')) {
            return Response::error('New entries need a type: '.implode(', ', KnowledgeEntry::TYPES).'.');
        }

        if ($entry !== null
            && $request->has('expected_version')
            && $entry->version !== $request->integer('expected_version')) {
            return $this->jsonResponse([
                'updated' => false,
                'conflict' => true,
                'entry' => $entry->name,
                'current_version' => $entry->version,
                'hint' => 'The entry changed since you read it — re-read it with knowledge_lookup and merge before writing.',
            ]);
        }

        $entry ??= new KnowledgeEntry(['name' => $name, 'name_lower' => Str::lower($name)]);

        DB::transaction(function () use ($request, $entry, $name, $agent) {
            $entry->name = $name;
            $entry->name_lower = Str::lower($name);

            foreach (['type', 'summary', 'state', 'source'] as $field) {
                if ($request->has($field)) {
                    $entry->{$field} = $request->get($field);
                }
            }

            foreach (['aliases', 'related'] as $field) {
                if ($request->has($field)) {
                    $entry->{$field} = array_values($request->get($field) ?? []);
                }
            }

            if ($request->boolean('verified')) {
                $entry->verified_at = now();
            }

            $entry->summary ??= '';
            $entry->state ??= '';
            $entry->aliases ??= [];
            $entry->related ??= [];

            $entry->updated_by = $agent->name;
            $entry->version = ($entry->version ?? 0) + 1;
            $entry->save();

            $entry->recordVersion($agent->name);
        });

        RateLimiter::hit($rateKey, 60);

        return $this->jsonResponse([
            'updated' => true,
            'created' => $entry->wasRecentlyCreated,
            'entry' => $entry->name,
            'version' => $entry->version,
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
            'entry' => $schema->string()->description('Canonical name of the knowledge entry — the lookup key, matched case-insensitively. (`name` stays reserved for your persona.)')->required(),
            'type' => $schema->string()->enum(KnowledgeEntry::TYPES)->description('Entry type. Required when creating.'),
            'aliases' => $schema->array()->items($schema->string())->description('Other names this entry answers to.'),
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
