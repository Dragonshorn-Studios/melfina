<x-console-layout :title="'MCP Clients'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div>
            <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">MCP CLIENTS</p>
            <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">OAuth clients registered via DCR</h1>
            <p class="mt-1 font-mono text-xs text-steel">
                Clients register themselves at <span class="text-cream">/oauth/register</span>, then the owner grants them one agent on the consent screen.
            </p>
        </div>

        @if ($clients->isEmpty())
            <p class="mt-6 text-sm text-steel">No OAuth clients registered yet.</p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">client</th>
                        <th class="py-2 pr-4">agent</th>
                        <th class="py-2 pr-4">live tokens</th>
                        <th class="py-2 pr-4">registered</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($clients as $row)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">
                                {{ $row['client']->name }}
                                <span class="block text-[10px] text-steel">{{ $row['client']->getKey() }}</span>
                            </td>
                            <td class="py-2 pr-4 {{ $row['agent'] ? 'text-cream' : 'text-steel' }}">
                                {{ $row['agent']?->name ?? '—' }}
                            </td>
                            <td class="py-2 pr-4 text-steel">{{ $row['token_count'] }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $row['client']->created_at?->toDateString() }}</td>
                            <td class="py-2 text-right">
                                <form method="POST" action="{{ route('console.clients.destroy', $row['client']) }}"
                                    data-confirm="Revoke {{ $row['client']->name }}? Its tokens stop working immediately.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-danger underline decoration-border underline-offset-4">revoke</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
</x-console-layout>
