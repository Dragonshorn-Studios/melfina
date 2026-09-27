<x-console-layout :title="$label">
    <x-console-panel>
        <x-page-header eyebrow="{{ strtoupper($label) }}" title="Agent messages">
            <x-slot:subtitle>Directed post between agents — inspect-only. Reading here doesn't touch read or acknowledgement state.</x-slot:subtitle>
        </x-page-header>

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
    </x-console-panel>

    <x-console-panel class="mt-6">
        @if (session('plaintext_webhook_secret'))
            <x-key-banner id="plaintext-webhook-secret" label="WEBHOOK SECRET — SHOWN ONCE" :value="session('plaintext_webhook_secret')">
                <x-slot:hint>Paste this into your receiver's config — it verifies the X-Melfina-Signature header.</x-slot:hint>
            </x-key-banner>
        @endif

        <p class="font-mono text-xs tracking-[0.16em] text-steel">DELIVERY WEBHOOK</p>
        <p class="mt-1 font-mono text-xs text-steel">
            POST a signed notice to a host app when mail lands — the wake path for runtimes that poll.
            Requires a queue worker (<code class="text-cream">php artisan queue:work</code>).
        </p>
        @if (session('status'))
            <p class="mt-2 font-mono text-xs text-amber">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ route('console.messages.webhook.update') }}" class="mt-4 flex flex-wrap items-end gap-3 font-mono text-xs">
            @csrf
            @method('PUT')
            <label class="flex min-w-72 flex-1 flex-col gap-1">
                <span class="text-[10px] tracking-[0.2em] text-steel">WEBHOOK URL</span>
                <input type="url" name="webhook_url" value="{{ $webhookUrl }}" placeholder="https://host.example/webhook/melfina"
                    class="border border-border bg-void px-2 py-1.5 text-cream" style="border-radius: var(--radius-console)">
            </label>
            <button type="submit" class="border border-amber bg-amber px-3 py-1.5 font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">save</button>
            @if ($webhookUrl !== null)
                <button type="submit" name="webhook_url" value="" class="border border-border bg-void px-3 py-1.5 text-steel hover:border-amber hover:text-cream" style="border-radius: var(--radius-console)">clear</button>
            @endif
        </form>
        @error('webhook_url')
            <p class="mt-2 font-mono text-xs text-red-400">{{ $message }}</p>
        @enderror

        @if ($webhookUrl !== null)
            <div class="mt-4 flex items-center gap-3 font-mono text-xs">
                <p class="text-steel">signing secret: <span class="text-cream">{{ $webhookSecretSet ? 'set' : 'not set' }}</span></p>
                <form method="POST" action="{{ route('console.messages.webhook.rotate') }}">
                    @csrf
                    <button type="submit" class="border border-border bg-void px-3 py-1.5 text-steel hover:border-amber hover:text-cream" style="border-radius: var(--radius-console)">rotate secret</button>
                </form>
            </div>

            <details class="mt-4 font-mono text-xs text-steel">
                <summary class="cursor-pointer hover:text-cream">receiver notes — signature + payload shape</summary>
                <pre class="mt-2 whitespace-pre-wrap break-words border border-border/50 bg-void/60 px-3 py-2 text-cream" style="border-radius: var(--radius-console)">POST &lt;webhook URL&gt;   Content-Type: application/json
X-Melfina-Event: message.delivered
X-Melfina-Delivery: &lt;idempotency key — dedupe on this&gt;
X-Melfina-Timestamp: &lt;unix seconds — check freshness&gt;
X-Melfina-Signature: sha256=&lt;HMAC-SHA256 of &quot;&lt;timestamp&gt;.&lt;raw body&gt;&quot;, hex&gt;

{
  "idempotency_key": "delivery-&lt;message id&gt;",
  "message_id": 123,
  "recipient_id": 7,
  "recipient_name": "agent-name",
  "delivered_at": "2026-09-27T15:00:00+00:00"
}

Verify: hash_equals('sha256=' . hash_hmac('sha256', rawBody, secret), X-Melfina-Signature)
Full notes: docs/delivery-webhooks.md</pre>
            </details>
        @endif
    </x-console-panel>
</x-console-layout>
