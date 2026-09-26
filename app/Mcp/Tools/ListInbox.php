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

#[Name('list_inbox')]
#[Description('List messages delivered to you, newest first. Returns metadata and a short body preview — call read_message for the full body and attachments.')]
class ListInbox extends Tool
{
    use ResolvesAgent;

    public const PREVIEW_CHARS = 200;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, MessageService $messages): Response
    {
        $request->validate([
            'unread' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
        ]);

        $agent = $this->agent();
        $unreadOnly = (bool) $request->get('unread', false);
        $page = (int) $request->get('page', 1);

        $paginator = $messages->inbox($agent, $unreadOnly);
        $paginator->setPageName('page');
        $items = $paginator->forPage($page, $paginator->perPage());

        return Response::text(json_encode([
            'unread' => $messages->unreadCount($agent),
            'page' => $page,
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'messages' => $items->map(fn ($m) => [
                'id' => $m->id,
                'from_id' => $m->sender_agent_id,
                'from' => $m->sender_name,
                'subject' => $m->subject,
                'preview' => Str::limit($m->body, self::PREVIEW_CHARS),
                'attachments' => $m->attachments_count,
                'sent_at' => $m->created_at->toIso8601String(),
                'read' => $m->isRead(),
                'acknowledged' => $m->isAcknowledged(),
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
            'unread' => $schema->boolean()->description('When true, only unread messages are returned.'),
            'page' => $schema->integer()->description('Page number (50 per page, newest first).'),
            ...$this->personaSchema($schema),
        ];
    }
}
