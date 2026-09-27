<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use App\Models\Agent;
use App\Models\AgentMessage;
use App\Models\VfsFile;
use App\Services\MessageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('send_message')]
#[Description('Send a directed message to one agent (delivers to their inbox; state-changing). Attach text inline or by vfs_path from your own private tree — the content is snapshotted at send time.')]
class SendMessage extends Tool
{
    use ResolvesAgent;

    /**
     * Sends allowed per minute per agent — dedicated limiter so message
     * floods can't piggyback on the file-write budget.
     */
    public const SEND_LIMIT_PER_MINUTE = 20;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, MessageService $messages): Response
    {
        $request->validate([
            'recipient_id' => 'required|integer',
            'subject' => 'nullable|string',
            'body' => 'required|string',
            'attachments' => 'nullable|array',
        ]);

        $agent = $this->agent();

        $rateKey = 'mcp-message-send:'.$agent->id;

        if (RateLimiter::tooManyAttempts($rateKey, self::SEND_LIMIT_PER_MINUTE)) {
            return Response::error('Send rate limit exceeded. Try again in '.RateLimiter::availableIn($rateKey).' seconds.');
        }

        try {
            $attachments = $this->resolveAttachments($agent, $request->get('attachments') ?? []);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        try {
            $message = $messages->send(
                $agent,
                (int) $request->get('recipient_id'),
                $request->get('subject'),
                $request->get('body'),
                $attachments,
            );
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        RateLimiter::hit($rateKey, 60);

        return $this->jsonResponse([
            'id' => $message->id,
            'recipient_id' => $message->recipient_agent_id,
            'recipient_name' => $message->recipient_name,
            'subject' => $message->subject,
            'attachments' => $message->attachments->count(),
            'sent_at' => $message->created_at->toIso8601String(),
        ]);
    }

    /**
     * Turn tool input into immutable snapshots. `vfs_path` entries are read
     * strictly inside the sender's own private tree — the path can never
     * reach into another agent's VFS.
     *
     * @param  array<int, array<string, mixed>>  $input
     * @return array<int, array{name: string, content: string}>
     */
    private function resolveAttachments(Agent $agent, array $input): array
    {
        $attachments = [];

        foreach ($input as $i => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException("attachments[$i] must be an object.");
            }

            if (isset($item['vfs_path'])) {
                if (! is_string($item['vfs_path'])) {
                    throw new InvalidArgumentException("attachments[$i]: vfs_path must be a string.");
                }

                $name = VfsFile::normalizePath($item['vfs_path']);
                $file = VfsFile::fileFor($agent, $name);

                if ($file === null) {
                    throw new InvalidArgumentException("attachments[$i]: no file at '$name' in your private tree.");
                }

                if ($file->isBinary()) {
                    throw new InvalidArgumentException("attachments[$i]: '$name' is a binary file ({$file->mime}) — attachments carry text only.");
                }

                $content = $file->bytes();

                $attachments[] = ['name' => $name, 'content' => $content];

                continue;
            }

            if (! isset($item['name'], $item['content'])) {
                throw new InvalidArgumentException("attachments[$i] needs name+content or vfs_path.");
            }

            if (! is_string($item['name']) || ! is_string($item['content'])) {
                throw new InvalidArgumentException("attachments[$i]: name and content must be strings.");
            }

            $attachments[] = ['name' => $item['name'], 'content' => $item['content']];
        }

        return $attachments;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'recipient_id' => $schema->integer()->description('Agent id from list_message_recipients.')->required(),
            'subject' => $schema->string()->description('Optional short subject line.'),
            'body' => $schema->string()->description('Message text.')->required(),
            'attachments' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->description('File name shown to the recipient (with content).'),
                    'content' => $schema->string()->description('Inline text content (with name).'),
                    'vfs_path' => $schema->string()->description('Path in your own private tree to attach instead of inline content.'),
                ])->withoutAdditionalProperties())
                ->max(AgentMessage::MAX_ATTACHMENTS)
                ->description('Optional text attachments (max '.AgentMessage::MAX_ATTACHMENTS.').'),
            ...$this->personaSchema($schema),
        ];
    }
}
