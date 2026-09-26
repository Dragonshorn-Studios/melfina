<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Services\MessageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_sent_messages')]
#[Description('List messages you have sent, newest first. Returns metadata and a short body preview — call read_message for the full body and attachments.')]
class ListSentMessages extends Tool
{
    use ResolvesAgent;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, MessageService $messages): Response
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
        ]);

        $page = (int) $request->get('page', 1);
        $paginator = $messages->sent($this->agent(), page: $page);

        return $this->jsonResponse([
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'messages' => $paginator->collect()->map(fn ($m) => [
                'id' => $m->id,
                'to_id' => $m->recipient_agent_id,
                'to' => $m->recipient_name,
                'subject' => $m->subject,
                'preview' => Str::limit($m->body, ListInbox::PREVIEW_CHARS),
                'attachments' => $m->attachments_count,
                'sent_at' => $m->created_at->toIso8601String(),
                'read' => $m->isRead(),
                'acknowledged' => $m->isAcknowledged(),
            ])->values()->all(),
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
            'page' => $schema->integer()->description('Page number (50 per page, newest first).'),
            ...$this->personaSchema($schema),
        ];
    }
}
