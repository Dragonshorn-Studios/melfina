<x-console-layout :title="$label">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div>
            <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">{{ strtoupper($label) }}</p>
            <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Agent messages</h1>
            <p class="mt-2 text-sm text-steel">Directed post between agents — inspect-only. Reading here doesn't touch read or acknowledgement state.</p>
        </div>

        <form method="GET" action="{{ route('console.messages.index') }}" class="mt-6 flex flex-wrap items-end gap-3 font-mono text-xs">
            <label class="flex flex-col gap-1">
                <span class="text-[10px] tracking-[0.2em] text-steel">AGENT</span>
                <select name="agent" class="border border-border bg-void px-2 py-1.5 text-cream" style="border-radius: var(--radius-console)">
                    <option value="">all</option>
                    @foreach ($agents as $filterAgent)
                        <option value="{{ $filterAgent->id }}" @selected($filters['agent'] === $filterAgent->id)>{{ $filterAgent->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-[10px] tracking-[0.2em] text-steel">DIRECTION</span>
                <select name="direction" class="border border-border bg-void px-2 py-1.5 text-cream" style="border-radius: var(--radius-console)">
                    <option value="">both</option>
                    <option value="inbox" @selected($filters['direction'] === 'inbox')>inbox</option>
                    <option value="sent" @selected($filters['direction'] === 'sent')>sent</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-[10px] tracking-[0.2em] text-steel">READ</span>
                <select name="read" class="border border-border bg-void px-2 py-1.5 text-cream" style="border-radius: var(--radius-console)">
                    <option value="">all</option>
                    <option value="unread" @selected($filters['read'] === 'unread')>unread</option>
                    <option value="read" @selected($filters['read'] === 'read')>read</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-[10px] tracking-[0.2em] text-steel">ACK</span>
                <select name="ack" class="border border-border bg-void px-2 py-1.5 text-cream" style="border-radius: var(--radius-console)">
                    <option value="">all</option>
                    <option value="unacked" @selected($filters['ack'] === 'unacked')>awaiting ack</option>
                    <option value="acked" @selected($filters['ack'] === 'acked')>acknowledged</option>
                </select>
            </label>
            <button type="submit" class="border border-amber bg-amber px-3 py-1.5 font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">filter</button>
            <a href="{{ route('console.messages.index') }}" class="border border-border bg-void px-3 py-1.5 text-steel hover:border-amber hover:text-cream" style="border-radius: var(--radius-console)">clear</a>
        </form>

        @if ($messages->total() === 0)
            <p class="mt-6 text-sm text-steel">
                @if (array_filter($filters, fn ($v) => $v !== null))
                    No messages match these filters.
                @else
                    No messages yet — agents send through MCP (`send_message`).
                @endif
            </p>
        @else
            <table class="mt-6 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">from</th>
                        <th class="py-2 pr-4">to</th>
                        <th class="py-2 pr-4">subject</th>
                        <th class="py-2 pr-4">sent</th>
                        <th class="py-2 pr-4">read</th>
                        <th class="py-2 pr-4">ack</th>
                        <th class="py-2 pr-4">files</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($messages as $message)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream">{{ $message->sender_name }}</td>
                            <td class="py-2 pr-4 text-cream">{{ $message->recipient_name }}</td>
                            <td class="max-w-48 truncate py-2 pr-4 {{ $message->isRead() ? 'text-steel' : 'text-cream' }}">
                                @if (! $message->isRead())
                                    <span class="mr-1 inline-block border border-amber px-1 text-[10px] text-amber" style="border-radius: var(--radius-console)">unread</span>
                                @endif
                                {{ $message->subject ?? \Illuminate\Support\Str::limit($message->body, 40) }}
                            </td>
                            <td class="py-2 pr-4 text-steel">{{ $message->created_at->toDateTimeString() }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $message->read_at?->toDateTimeString() ?? '—' }}</td>
                            <td class="py-2 pr-4">
                                @if ($message->isAcknowledged())
                                    <span class="text-steel">{{ $message->acknowledged_at->toDateTimeString() }}</span>
                                @else
                                    <span class="text-amber">awaiting</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4 text-steel">{{ $message->attachments_count }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.messages.show', $message) }}" class="text-amber underline decoration-border underline-offset-4">open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">
                {{ $messages->links() }}
            </div>
        @endif
    </main>
</x-console-layout>
