<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeObservationKind;
use App\Models\KnowledgePredicate;
use App\Services\KnowledgeGraph;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_connect')]
#[Description('Assert or retract a typed link between two internal knowledge entities, and attach the event data that justifies it. Use this to connect the dots — runs_on, depends_on, involves, owned_by — rather than stuffing names into knowledge_upsert related. Confidence rises when other agents independently assert the same link; the owner can lock a link permanent from the console. Missing endpoints are not created unless create_missing is true.')]
class KnowledgeConnect extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, KnowledgeGraph $graph): Response
    {
        $request->validate([
            'from' => 'required|string|max:200',
            'to' => 'required|string|max:200',
            'predicate' => ['required', 'string', Rule::in(KnowledgePredicate::values())],
            'op' => 'nullable|string|in:assert,retract',
            'note' => 'nullable|string|max:500',
            'data' => 'nullable|array',
            'source' => 'nullable|string|max:500',
            'occurred_at' => 'nullable|date',
            'event' => 'nullable|string|max:200',
            'create_missing' => 'nullable|boolean',
        ]);

        $agent = $this->agent();
        $eventCost = $request->filled('event') ? 2 : 0;

        if ($limited = KnowledgeUpsert::consumeWriteQuota($agent->id, 1 + $eventCost)) {
            return $limited;
        }

        $data = $request->get('data');
        if (is_array($data) && strlen((string) json_encode($data)) > 4000) {
            return Response::error('Event data is too large (max 4000 JSON characters).');
        }

        $op = $request->string('op')->toString() === 'retract'
            ? KnowledgeObservationKind::LinkRetract
            : KnowledgeObservationKind::LinkAssert;

        $occurred = $request->filled('occurred_at')
            ? Carbon::parse($request->string('occurred_at')->toString())
            : null;

        $result = $graph->connect(
            author: $agent->name,
            authorKind: KnowledgeAuthorKind::Agent,
            from: trim($request->string('from')->toString()),
            to: trim($request->string('to')->toString()),
            predicate: KnowledgePredicate::from($request->string('predicate')->toString()),
            op: $op,
            note: $request->filled('note') ? $request->string('note')->toString() : null,
            data: is_array($data) ? $data : null,
            source: $request->filled('source') ? $request->string('source')->toString() : null,
            occurredAt: $occurred,
            createMissing: $request->boolean('create_missing'),
            eventName: $request->filled('event') ? $request->string('event')->toString() : null,
        );

        if (! $result->ok) {
            $payload = [
                'updated' => false,
                'error' => $result->error ?? 'Could not write the link.',
            ];
            if ($result->unresolved !== null) {
                $payload['unresolved'] = $result->unresolved;
                $payload['hint'] = 'No internal entity matches that name. Create it with knowledge_upsert, pass create_missing, or check the spelling.';
            }

            return $this->jsonResponse($payload);
        }

        $link = $result->link;

        return $this->jsonResponse([
            'updated' => true,
            'created' => $result->created,
            'from' => $link->fromEntry->name,
            'to' => $link->toEntry->name,
            'predicate' => $link->predicate->value,
            'status' => $link->status->value,
            'confidence' => $link->confidence,
            'permanent' => $link->permanent,
            'disputed' => $link->disputed,
            'observation_id' => $result->observation?->id,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Canonical name or alias of the source entity.')->required(),
            'to' => $schema->string()->description('Canonical name or alias of the target entity.')->required(),
            'predicate' => $schema->string()->enum(KnowledgePredicate::values())->description('Typed meaning of the link. Inverse names (hosts, owns, …) are stored as the canonical opposite with ends swapped.')->required(),
            'op' => $schema->string()->enum(['assert', 'retract'])->description('assert (default) strengthens the link; retract records a disagreement. Permanent links reject agent retracts.'),
            'note' => $schema->string()->description('One-line why this connection exists.'),
            'data' => $schema->object()->description('Structured event payload for this assertion (version, host, ticket id, …).'),
            'source' => $schema->string()->description('Provenance — doc, ticket, chat, deploy log.'),
            'occurred_at' => $schema->string()->description('When the real-world event happened (ISO-8601). Distinct from when you recorded it.'),
            'event' => $schema->string()->description('If set, also upsert a named event entity and involve both ends.'),
            'create_missing' => $schema->boolean()->description('Create stub concept entities for unknown names. Default false so typos do not spawn nodes.'),
            ...$this->personaSchema($schema),
        ];
    }
}
