{{-- One directory level: breadcrumb trail + subdirectory links.
     Expects: crumbs — list of ['name', 'url']; dirs — list of ['name', 'url']. --}}
<nav class="mt-6 flex flex-wrap items-center gap-1 font-mono text-xs" aria-label="directories">
    @foreach ($crumbs as $crumb)
        @if (! $loop->first)
            <span class="text-steel/60">/</span>
        @endif
        <a href="{{ $crumb['url'] }}"
            class="{{ $loop->last ? 'text-amber' : 'text-steel hover:text-cream' }}">{{ $crumb['name'] }}</a>
    @endforeach
</nav>

@if ($dirs)
    <ul class="mt-3 flex flex-wrap gap-1 font-mono text-xs">
        @foreach ($dirs as $dirLink)
            <li>
                <a href="{{ $dirLink['url'] }}"
                    class="inline-flex items-center gap-1 border border-border bg-void/60 px-3 py-1.5 text-steel hover:border-amber hover:text-cream"
                    style="border-radius: var(--radius-console)">{{ $dirLink['name'] }}/</a>
            </li>
        @endforeach
    </ul>
@endif
