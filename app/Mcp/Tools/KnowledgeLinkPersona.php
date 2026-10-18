<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use App\Services\PersonaLinks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_link_persona')]
#[Description('Link two person entries as the same living being with different presentations. Lookup keeps each entry\'s own name and reports that they are equal. This does not rename either person.')]
class KnowledgeLinkPersona extends Tool
{
    use ResolvesAgent;

    public function handle(Request $request, KnowledgeIdentityResolver $resolver, PersonaLinks $personas): Response
    {
        $request->validate([
            'person' => 'required|string|max:200',
            'person_id' => 'nullable|integer|min:1',
            'persona' => 'required|string|max:200',
            'persona_id' => 'nullable|integer|min:1',
        ]);

        $agent = $this->agent();

        if ($limited = KnowledgeUpsert::consumeWriteQuota($agent->id, 1)) {
            return $limited;
        }

        $context = KnowledgeIdentityContext::forAgent($agent);
        $person = $resolver->resolve(
            $context,
            query: $request->string('person')->toString(),
            entryId: $request->filled('person_id') ? $request->integer('person_id') : null,
        );
        $persona = $resolver->resolve(
            $context,
            query: $request->string('persona')->toString(),
            entryId: $request->filled('persona_id') ? $request->integer('persona_id') : null,
        );

        if (! $person->found() || $person->entry === null) {
            return Response::error($person->error ?? 'No matching person.');
        }

        if (! $persona->found() || $persona->entry === null) {
            return Response::error($persona->error ?? 'No matching persona.');
        }

        try {
            $personas->link($person->entry, $persona->entry);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first() ?: 'Could not link the persona.');
        }

        return $this->jsonResponse([
            'updated' => true,
            'person' => $person->entry->name,
            'person_id' => $person->entry->id,
            'persona' => $persona->entry->name,
            'persona_id' => $persona->entry->id,
            'same_being' => true,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'person' => $schema->string()->description('One person entry.')->required(),
            'person_id' => $schema->integer()->description('Stable id for person. When supplied, it must agree with person.'),
            'persona' => $schema->string()->description('The other person entry — a different presentation of the same being.')->required(),
            'persona_id' => $schema->integer()->description('Stable id for persona. When supplied, it must agree with persona.'),
            ...$this->personaSchema($schema),
        ];
    }
}
