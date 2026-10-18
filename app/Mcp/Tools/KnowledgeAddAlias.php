<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\KnowledgeIdentifierKind;
use App\Services\KnowledgeIdentityAdmin;
use App\Services\KnowledgeIdentityContext;
use App\Services\KnowledgeIdentityResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('knowledge_add_alias')]
#[Description('Add another name for an existing knowledge entity. An alias is the same entity — a project, a place, or a person — not a separate presentation. Lookup of the alias returns that entity.')]
class KnowledgeAddAlias extends Tool
{
    use ResolvesAgent;

    public function handle(Request $request, KnowledgeIdentityResolver $resolver, KnowledgeIdentityAdmin $admin): Response
    {
        $request->validate([
            'entry' => 'required|string|max:200',
            'entry_id' => 'nullable|integer|min:1',
            'alias' => 'required|string|max:200',
        ]);

        $agent = $this->agent();

        if ($limited = KnowledgeUpsert::consumeWriteQuota($agent->id, 1)) {
            return $limited;
        }

        $resolved = $resolver->resolve(
            KnowledgeIdentityContext::forAgent($agent),
            query: $request->string('entry')->toString(),
            entryId: $request->filled('entry_id') ? $request->integer('entry_id') : null,
        );

        if (! $resolved->found() || $resolved->entry === null) {
            return Response::error($resolved->error ?? 'No matching entry.');
        }

        try {
            $identifier = $admin->addIdentifier(
                $resolved->entry,
                $request->string('alias')->toString(),
                KnowledgeIdentifierKind::Alias,
                $agent->name,
            );
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first() ?: 'Could not store the alias.');
        }

        return $this->jsonResponse([
            'updated' => true,
            'entry' => $resolved->entry->name,
            'entry_id' => $resolved->entry->id,
            'alias' => $identifier->value,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'entry' => $schema->string()->description('Target entity: canonical name, alias, or this agent\'s contextual title.')->required(),
            'entry_id' => $schema->integer()->description('Stable entity id. When supplied with entry, both must name the same entity.'),
            'alias' => $schema->string()->description('Another name for this same entity.')->required(),
            ...$this->personaSchema($schema),
        ];
    }
}
