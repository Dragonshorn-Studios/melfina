<x-console-layout :title="$label.' — message #'.$message->id">
    <x-console-panel>
        <x-page-header eyebrow="{{ strtoupper($label) }}" title="{{ $message->subject ?? 'Message #'.$message->id }}">
            <x-slot:subtitle>{{ $message->sender_name }} → {{ $message->recipient_name }} · {{ $message->created_at->toDateTimeString() }}</x-slot:subtitle>
            <x-slot:actions>
<a href="{{ route('console.messages.index') }}"
                class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                style="border-radius: var(--radius-console)">back</a>
            </x-slot:actions>
        </x-page-header>

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
    </x-console-panel>
</x-console-layout>
