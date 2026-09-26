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

#[Name('read_message')]
#[Description('Read one message you sent or received — full body plus attachment snapshots. Reading as the recipient marks it read (state-changing for inbox items).')]
class ReadMessage extends Tool
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

        $message = $messages->readFor($this->agent(), (int) $request->get('id'));

        if ($message === null) {
            return Response::error('Message not found.');
        }

        return Response::text(json_encode([
            'id' => $message->id,
            'from_id' => $message->sender_agent_id,
            'from' => $message->sender_name,
            'to_id' => $message->recipient_agent_id,
            'to' => $message->recipient_name,
            'subject' => $message->subject,
            'body' => $message->body,
            'sent_at' => $message->created_at->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
            'acknowledged_at' => $message->acknowledged_at?->toIso8601String(),
            'attachments' => $message->attachments->map(fn ($a) => [
                'name' => $a->name,
                'bytes' => strlen($a->content),
                'content' => $a->content,
            ])->values()->all(),
        ]));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Message id from list_inbox or list_sent_messages.')->required(),
            ...$this->personaSchema($schema),
        ];
    }
}
