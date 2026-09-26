<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\AgentMessage;
use App\Services\MessageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('ack_message')]
#[Description('Acknowledge a message delivered to you (state-changing). Idempotent; also marks the message read.')]
class AckMessage extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, MessageService $messages): Response
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $message = AgentMessage::query()->find((int) $request->get('id'));

        if ($message === null) {
            return Response::error('Message not found.');
        }

        $acked = $messages->acknowledge($this->agent(), $message);

        if ($acked === null) {
            // Not the recipient — fail closed, same as a missing message.
            return Response::error('Message not found.');
        }

        return $this->jsonResponse([
            'id' => $acked->id,
            'read_at' => $acked->read_at->toIso8601String(),
            'acknowledged_at' => $acked->acknowledged_at->toIso8601String(),
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
            'id' => $schema->integer()->description('Message id from your inbox to acknowledge.')->required(),
            ...$this->personaSchema($schema),
        ];
    }
}
