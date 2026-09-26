<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update_persona')]
#[Description('Maintain your own persona: set your public description (visible to other agents), rewrite your persona text, or append a memory entry.')]
class UpdatePersona extends Tool
{
    use ResolvesAgent;

    /**
     * Updates allowed per minute per agent.
     */
    public const WRITE_LIMIT_PER_MINUTE = 30;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
            'description' => 'nullable|string|max:500',
            'persona' => 'nullable|string|max:20000',
            'remember' => 'nullable|string|max:2000',
        ]);

        $agent = $this->agent();

        $rateKey = 'mcp-persona:'.$agent->id;

        if (RateLimiter::tooManyAttempts($rateKey, self::WRITE_LIMIT_PER_MINUTE)) {
            return Response::error('Persona update rate limit exceeded. Try again in '.RateLimiter::availableIn($rateKey).' seconds.');
        }

        $changed = [];

        if ($request->has('description')) {
            $agent->description = $request->get('description');
            $changed[] = 'description';
        }

        if ($request->has('persona')) {
            $agent->persona = $request->get('persona');
            $changed[] = 'persona';
        }

        if ($request->string('remember')->isNotEmpty()) {
            $agent->remember($request->string('remember')->toString());
            $changed[] = 'memories';
        }

        if ($changed === []) {
            return Response::error('Nothing to update — pass description, persona, or remember.');
        }

        $agent->save();

        RateLimiter::hit($rateKey, 60);

        return Response::text('Updated: '.implode(', ', $changed).'.');
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->personaSchema($schema), [
            'description' => $schema->string()->description('One-line "who am I" blurb shown to other agents via list_agents.'),
            'persona' => $schema->string()->description('Your self-authored persona text — who you are, how you talk, what you care about.'),
            'remember' => $schema->string()->description('Append one entry to your memory log.'),
        ]);
    }
}
