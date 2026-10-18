<x-console-layout :title="'Setup'" :bare="true">
    <x-console-panel>
        <x-page-header eyebrow="SETUP" title="You need to run migrations">
            <x-slot:subtitle>
                @if ($current)
                    Schema is current. MCP and the HTTP tools API are enabled.
                @else
                    New code is on disk but the database is behind. MCP and the HTTP tools API stay disabled until these finish.
                @endif
            </x-slot:subtitle>
        </x-page-header>

        @if ($pending !== [])
            <p class="mt-6 font-mono text-[10px] tracking-[0.14em] text-steel">PENDING</p>
            <ol id="setup-pending" class="mt-2 space-y-1 font-mono text-xs text-cream">
                @foreach ($pending as $name)
                    <li class="border border-border/60 bg-void/40 px-3 py-1.5" style="border-radius: var(--radius-console)">{{ $name }}</li>
                @endforeach
            </ol>
        @endif

        <p class="mt-6 font-mono text-[10px] tracking-[0.14em] text-steel">PROGRESS</p>
        <ol id="setup-log" class="mt-2 min-h-10 space-y-1 font-mono text-xs">
            @forelse ($report as $step)
                <li class="{{ $step['status'] === 'failed' ? 'text-danger' : 'text-amber' }}">
                    {{ $step['status'] }} · {{ $step['name'] }}@if ($step['detail']) · {{ $step['detail'] }}@endif
                </li>
            @empty
                <li class="text-steel">Waiting to run.</li>
            @endforelse
        </ol>

        <div class="mt-6 flex flex-wrap gap-2">
            @if (! $current)
                <form id="setup-form" method="POST" action="{{ route('console.setup.run') }}">
                    @csrf
                    <button id="setup-run" type="submit"
                        class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim disabled:opacity-50"
                        style="border-radius: var(--radius-console)">run migrations</button>
                </form>
            @endif
            @if ($current)
                <a href="{{ route('console.dashboard') }}"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">continue to console</a>
            @endif
        </div>
    </x-console-panel>

    @if (! $current)
        <script>
            (() => {
                const form = document.getElementById('setup-form');
                const log = document.getElementById('setup-log');
                const button = document.getElementById('setup-run');
                if (!form || !log || !button) return;

                const line = (text, failed) => {
                    const li = document.createElement('li');
                    li.className = failed ? 'text-danger' : 'text-amber';
                    li.textContent = text;
                    log.appendChild(li);
                };

                form.addEventListener('submit', async (event) => {
                    if (!window.fetch || !window.ReadableStream) return;

                    event.preventDefault();
                    button.disabled = true;
                    log.replaceChildren();
                    line('running…', false);

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/x-ndjson',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: new FormData(form),
                        });

                        if (!response.ok || !response.body) {
                            form.submit();
                            return;
                        }

                        const reader = response.body.getReader();
                        const decoder = new TextDecoder();
                        let buffer = '';

                        while (true) {
                            const {value, done} = await reader.read();
                            if (done) break;
                            buffer += decoder.decode(value, {stream: true});
                            const parts = buffer.split('\n');
                            buffer = parts.pop() ?? '';
                            for (const part of parts) {
                                if (part.trim() === '') continue;
                                const step = JSON.parse(part);
                                if (step.status === 'done') {
                                    window.location.reload();
                                    return;
                                }
                                if (step.status === 'blocked') {
                                    window.location.reload();
                                    return;
                                }
                                const detail = step.detail ? ` · ${step.detail}` : '';
                                line(`${step.status} · ${step.name}${detail}`, step.status === 'failed');
                            }
                        }

                        window.location.reload();
                    } catch (error) {
                        form.submit();
                    }
                });
            })();
        </script>
    @endif
</x-console-layout>
