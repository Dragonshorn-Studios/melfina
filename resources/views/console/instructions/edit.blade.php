<x-console-layout :title="'Instructions — '.$agent->name">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">INSTRUCTIONS — {{ strtoupper($agent->name) }}</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Shape the brain</h1>
        <p class="mt-2 text-sm text-steel">
            Owner text plus optional sub-layers. Agents read these later via MCP — they can never edit them.
        </p>

        <form method="POST" action="{{ route('console.agents.instructions.update', $agent) }}" class="mt-6 space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label for="content" class="block font-mono text-xs text-steel">owner instructions</label>
                <textarea id="content" name="content" rows="8"
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">{{ old('content', $instruction->content) }}</textarea>
                @error('content') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block font-mono text-xs text-steel">sub-layers (ordered)</span>
                <div id="layers" class="mt-2 space-y-2">
                    @foreach (old('layers', $instruction->layersList()) as $layer)
                        <div class="flex items-start gap-2">
                            <textarea name="layers[]" rows="2"
                                class="w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">{{ $layer }}</textarea>
                            <button type="button" onclick="this.closest('div').remove()"
                                class="border border-border px-2 py-1 font-mono text-xs text-danger hover:border-danger" style="border-radius: var(--radius-console)">×</button>
                        </div>
                    @endforeach
                </div>
                @foreach ($errors->get('layers.*') as $layerErrors)
                    @foreach ($layerErrors as $layerError)
                        <p class="mt-1 font-mono text-xs text-danger">{{ $layerError }}</p>
                    @endforeach
                @endforeach
                <button type="button" id="add-layer"
                    class="mt-2 border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber" style="border-radius: var(--radius-console)">+ add layer</button>
            </div>

            <button type="submit"
                class="border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                save instructions
            </button>
            <a href="{{ route('console.agents.show', $agent) }}" class="ml-3 font-mono text-xs text-steel underline decoration-border underline-offset-4">back to agent</a>
        </form>
    </main>

    <script>
        document.getElementById('add-layer').addEventListener('click', function () {
            const wrap = document.createElement('div');
            wrap.className = 'flex items-start gap-2';
            wrap.innerHTML = '<textarea name="layers[]" rows="2" class="w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)"></textarea>'
                + '<button type="button" onclick="this.closest(\'div\').remove()" class="border border-border px-2 py-1 font-mono text-xs text-danger hover:border-danger" style="border-radius: var(--radius-console)">×</button>';
            document.getElementById('layers').appendChild(wrap);
        });
    </script>
</x-console-layout>
