<x-console-layout :title="'MCP Clients'">
    <x-console-panel>
        <x-page-header eyebrow="MCP CLIENTS" title="OAuth clients registered via DCR">
            <x-slot:subtitle>
                Clients register themselves at <span class="text-cream">/oauth/register</span>, then the owner grants them one agent on the consent screen.
            </x-slot:subtitle>
        </x-page-header>

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
                                <x-confirm-form action="{{ route('console.clients.destroy', $row['client']) }}" method="DELETE" confirm="Revoke {{ $row['client']->name }}? Its tokens stop working immediately.">
                                    <button type="submit" class="text-danger underline decoration-border underline-offset-4">revoke</button>
                                </x-confirm-form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-console-panel>
</x-console-layout>
