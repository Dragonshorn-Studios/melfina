<?php

namespace App\Services;

use App\Models\Agent;

/**
 * Explicit caller context for identity resolution. Agent-facing paths
 * pass the middleware-resolved agent. Do not read the current agent
 * from global request state inside {@see KnowledgeEntry::lookup()}.
 */
final readonly class KnowledgeIdentityContext
{
    public function __construct(
        public ?Agent $agent = null,
        public ?Agent $authorAgent = null,
        public bool $ownerConsole = false,
    ) {}

    public static function forAgent(Agent $agent): self
    {
        return new self(agent: $agent);
    }

    /**
     * Trusted author context from authorized message history — not an
     * arbitrarily claimed speaker_agent_id on a knowledge tool.
     */
    public static function forAgentWithAuthor(Agent $agent, Agent $author): self
    {
        return new self(agent: $agent, authorAgent: $author);
    }

    public static function forOwner(): self
    {
        return new self(ownerConsole: true);
    }

    public static function global(): self
    {
        return new self;
    }
}
