@props([
    'name',
    'options' => [],
    'selected' => null,
])

@php
    $selected = old($name, $selected);
    $cases = collect($options);
    if ($selected === null && $cases->isNotEmpty()) {
        $first = $cases->first();
        $selected = $first instanceof \BackedEnum ? $first->value : (string) $first;
    }
    $selectedCase = $cases->first(function ($option) use ($selected) {
        $value = $option instanceof \BackedEnum ? $option->value : (string) $option;

        return $value === $selected;
    });
    $selectedLabel = $selectedCase instanceof \BackedEnum
        ? $selectedCase->value
        : (string) ($selectedCase ?? $selected);
@endphp

<div {{ $attributes->class('relative min-w-44') }} data-described-select-root>
    <details class="group border border-border bg-void" style="border-radius: var(--radius-console)">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-2 py-1 font-mono text-xs text-cream marker:content-none [&::-webkit-details-marker]:hidden">
            <span data-described-select-label>{{ $selectedLabel }}</span>
            <span class="text-steel group-open:rotate-180">▾</span>
        </summary>
        <div class="absolute bottom-full left-0 z-20 mb-1 max-h-[min(20rem,50vh)] w-[min(22rem,calc(100vw-3rem))] overflow-y-auto border border-border bg-panel p-1 shadow-[0_8px_24px_rgba(11,18,32,0.6)]" style="border-radius: var(--radius-console); bottom: 100%">
            @foreach ($cases as $option)
                @php
                    $value = $option instanceof \BackedEnum ? $option->value : (string) $option;
                    $hint = is_object($option) && method_exists($option, 'hint') ? $option->hint() : null;
                @endphp
                <label class="flex cursor-pointer flex-col gap-0.5 border border-transparent px-2 py-1.5 hover:border-border hover:bg-void has-[:checked]:border-amber has-[:checked]:bg-void" style="border-radius: var(--radius-console)">
                    <span class="flex items-center gap-2 font-mono text-xs text-cream">
                        <input type="radio" name="{{ $name }}" value="{{ $value }}" class="accent-amber"
                            data-described-select data-label="{{ $value }}"
                            @checked($value === $selected) required>
                        {{ $value }}
                    </span>
                    @if ($hint)
                        <span class="pl-5 font-mono text-[10px] leading-snug text-steel">{{ $hint }}</span>
                    @endif
                </label>
            @endforeach
        </div>
    </details>
</div>
