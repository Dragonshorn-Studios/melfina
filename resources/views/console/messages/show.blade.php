<x-console-layout :title="$label.' — message #'.$message->id">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <div class="flex items-center justify-between">
            <div>
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">{{ strtoupper($label) }}</p>
                <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $message->subject ?? 'Message #'.$message->id }}</h1>
                <p class="mt-2 text-sm text-steel">{{ $message->sender_name }} → {{ $message->recipient_name }} · {{ $message->created_at->toDateTimeString() }}</p>
            </div>
            <a href="{{ route('console.messages.index') }}"
                class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                style="border-radius: var(--radius-console)">back</a>
        </div>

        <dl class="mt-6 flex flex-wrap gap-x-8 gap-y-2 font-mono text-xs">
            <div>
                <dt class="text-[10px] tracking-[0.2em] text-steel">SENT</dt>
                <dd class="mt-1 text-cream">{{ $message->created_at->toDateTimeString() }}</dd>
            </div>
            <div>
                <dt class="text-[10px] tracking-[0.2em] text-steel">READ</dt>
                <dd class="mt-1 {{ $message->isRead() ? 'text-cream' : 'text-amber' }}">{{ $message->read_at?->toDateTimeString() ?? 'unread' }}</dd>
            </div>
            <div>
                <dt class="text-[10px] tracking-[0.2em] text-steel">ACKNOWLEDGED</dt>
                <dd class="mt-1 {{ $message->isAcknowledged() ? 'text-cream' : 'text-amber' }}">{{ $message->acknowledged_at?->toDateTimeString() ?? 'awaiting' }}</dd>
            </div>
        </dl>

        <pre class="mt-6 whitespace-pre-wrap border border-border bg-void p-4 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">{{ $message->body }}</pre>

        @if ($message->attachments->isNotEmpty())
            <p class="mt-6 font-mono text-[10px] tracking-[0.2em] text-steel">ATTACHMENTS ({{ $message->attachments->count() }})</p>
            @foreach ($message->attachments as $attachment)
                <details class="mt-3 border border-border bg-void" style="border-radius: var(--radius-console)">
                    <summary class="cursor-pointer px-4 py-2 font-mono text-xs text-cream hover:text-amber">{{ $attachment->name }} <span class="text-steel">({{ strlen($attachment->content) }} B)</span></summary>
                    <pre class="overflow-x-auto border-t border-border px-4 py-3 font-mono text-xs text-steel">{{ $attachment->content }}</pre>
                </details>
            @endforeach
        @endif
    </main>
</x-console-layout>
