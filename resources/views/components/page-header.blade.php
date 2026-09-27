@props(['eyebrow', 'title'])

<div {{ $attributes->merge(['class' => 'flex items-center justify-between']) }}>
    <div>
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">{{ $eyebrow }}</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $title }}</h1>
        @isset($subtitle)
            <p class="mt-2 text-sm text-steel">{{ $subtitle }}</p>
        @endisset
    </div>
    @isset($actions)
        {{ $actions }}
    @endisset
</div>
