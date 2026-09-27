@props(['id', 'label', 'value'])

<div id="{{ $id }}" {{ $attributes->merge(['class' => 'mb-6 border border-amber bg-void p-4']) }} style="border-radius: var(--radius-console)">
    <div class="flex items-center justify-between">
        <p class="font-mono text-xs tracking-[0.16em] text-amber">{{ $label }}</p>
        <button type="button" onclick="document.getElementById('{{ $id }}').remove()"
            class="border border-border px-2 py-1 font-mono text-xs text-steel hover:border-amber hover:text-cream"
            style="border-radius: var(--radius-console)">dismiss</button>
    </div>
    <p class="mt-2 select-all break-all font-mono text-sm text-cream">{{ $value }}</p>
    @isset($hint)
        <p class="mt-2 font-mono text-xs text-steel">{{ $hint }}</p>
    @endisset
    {{ $slot }}
</div>
