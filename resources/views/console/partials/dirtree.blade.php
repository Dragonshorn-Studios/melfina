{{-- Path bar for a browsable directory level.
     Expects: crumbs — list of ['name', 'url'], root-first. --}}
<nav class="mt-6 flex flex-wrap items-center gap-1 font-mono text-xs" aria-label="directories">
    @foreach ($crumbs as $crumb)
        @if (! $loop->first)
            <span class="text-steel/60">/</span>
        @endif
        <a href="{{ $crumb['url'] }}" class="{{ $loop->last ? 'text-amber' : 'text-steel hover:text-cream' }}">{{ $crumb['name'] }}</a>
    @endforeach
</nav>
