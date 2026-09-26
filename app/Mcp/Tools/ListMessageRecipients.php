<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Services\MessageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_message_recipients')]
#[Description('List the agents you can send a message to — id and display name. Address messages by id: names are display-only.')]
class ListMessageRecipients extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, MessageService $messages): Response
    {
        return Response::text(json_encode([
            'recipients' => $messages->addressBook($this->agent())->values()->all(),
        ]));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->personaSchema($schema);
    }
}
