<x-console-layout :title="'Preview — '.$agent->name" :agent="$agent">
    <x-console-panel>
        <x-page-header eyebrow="PREVIEW" title="{{ $agent->name }}">
            <x-slot:subtitle>Exactly what the agent receives — read-only.</x-slot:subtitle>
            <x-slot:actions>
<div class="flex gap-2">
                <a href="{{ route('console.agents.instructions.edit', $agent) }}"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">edit instructions</a>
                <a href="{{ route('console.agents.persona.edit', $agent) }}"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">edit persona</a>
            </div>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">MCP SERVER INSTRUCTIONS</p>
            <p class="mt-1 font-mono text-xs text-steel">Sent at <span class="text-cream">initialize</span> — same for every agent on the install.</p>
            <pre class="mt-2 whitespace-pre-wrap break-words border border-border/50 bg-void/60 px-3 py-2 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $serverInstructions }}</pre>
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">READ_INSTRUCTIONS</p>
            <p class="mt-1 font-mono text-xs text-steel">Owner content + ordered sub-layers + the shared operator persona.</p>
            <pre class="mt-2 whitespace-pre-wrap break-words border border-border/50 bg-void/60 px-3 py-2 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ json_encode($instructionsPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">READ_PERSONA</p>
            <p class="mt-1 font-mono text-xs text-steel">The agent's self-authored persona and memory log.</p>
            <pre class="mt-2 whitespace-pre-wrap break-words border border-border/50 bg-void/60 px-3 py-2 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ json_encode($personaPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">GENERATED SKILL — skills/melfina/SKILL.md</p>
            <p class="mt-1 font-mono text-xs text-steel">The umbrella doc baked into this agent's plugin package.</p>
            <pre class="mt-2 whitespace-pre-wrap break-words border border-border/50 bg-void/60 px-3 py-2 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $umbrellaSkill }}</pre>
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">TOOL SURFACE</p>
            <table class="mt-3 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">tool</th>
                        <th class="py-2 pr-4">scope</th>
                        <th class="py-2 pr-4">description</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tools as $tool)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $tool['name'] }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $tool['scope'] }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $tool['description'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-console-panel>
</x-console-layout>
