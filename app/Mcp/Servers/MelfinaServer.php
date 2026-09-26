<?php

namespace App\Mcp\Servers;

use App\Mcp\Methods\MailListen;
use App\Mcp\Tools\AckMessage;
use App\Mcp\Tools\KnowledgeLookup;
use App\Mcp\Tools\KnowledgeSearch;
use App\Mcp\Tools\KnowledgeUpsert;
use App\Mcp\Tools\ListAgents;
use App\Mcp\Tools\ListFiles;
use App\Mcp\Tools\ListInbox;
use App\Mcp\Tools\ListMessageRecipients;
use App\Mcp\Tools\ListSentMessages;
use App\Mcp\Tools\ListShared;
use App\Mcp\Tools\ReadFile;
use App\Mcp\Tools\ReadInstructions;
use App\Mcp\Tools\ReadMessage;
use App\Mcp\Tools\ReadPersona;
use App\Mcp\Tools\ReadShared;
use App\Mcp\Tools\SendMessage;
use App\Mcp\Tools\UpdatePersona;
use App\Mcp\Tools\WriteFile;
use App\Mcp\Tools\WriteShared;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('melfina')]
#[Version('0.0.1')]
#[Instructions('You are connected to your own private Melfina brain. Every tool is scoped to the agent whose key authenticated this request: the file and instruction tools only see and touch your own tree, and the shared tools only reach folders you hold an ACL grant on. When the connection uses a shared fleet key, pass your agent name as the `name` argument on every tool call — that is how Melfina knows which imp you are. Internal nouns first, internet second: when an unfamiliar proper noun, codename, project, service, person, or convention comes up, call knowledge_lookup before reaching for external search.')]
class MelfinaServer extends Server
{
    /**
     * Swap the stock acknowledge-and-close `subscriptions/listen` for the
     * mail doorbell: a bounded SSE stream that yields
     * `notifications/melfina/mail` when an agent_message lands.
     */
    protected function boot(): void
    {
        $this->addMethod('subscriptions/listen', MailListen::class);
    }

    /**
     * The tool surface — the single list ToolCatalog derives the HTTP
     * fallback endpoints and generated skills from.
     */
    public const TOOLS = [
        ListFiles::class,
        ReadFile::class,
        WriteFile::class,
        ReadInstructions::class,
        ListShared::class,
        ReadShared::class,
        WriteShared::class,
        ReadPersona::class,
        UpdatePersona::class,
        ListAgents::class,
        ListMessageRecipients::class,
        SendMessage::class,
        ListInbox::class,
        ListSentMessages::class,
        ReadMessage::class,
        AckMessage::class,
        KnowledgeLookup::class,
        KnowledgeSearch::class,
        KnowledgeUpsert::class,
    ];

    protected array $tools = self::TOOLS;
}
