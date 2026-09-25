@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ? $title.' — ' : '' }}Melfina</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-void text-cream antialiased">
        <div class="mx-auto flex min-h-screen max-w-3xl flex-col px-6 py-12">
            <header class="mb-10 flex items-center justify-between border-b border-border pb-6">
                <div class="flex items-center gap-3">
                    <svg class="h-8 w-8 shrink-0 text-amber" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <path d="M24 4 L28 16 L44 20 L28 24 L24 44 L20 24 L4 20 L20 16 Z" stroke="currentColor" stroke-width="2" fill="none"/>
                        <circle cx="24" cy="20" r="3" fill="currentColor"/>
                    </svg>
                    <p class="font-mono text-xs tracking-[0.2em] text-amber">MELFINA</p>
                </div>
                @auth
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="font-mono text-xs text-steel underline decoration-border underline-offset-4 hover:text-amber">
                            logout
                        </button>
                    </form>
                @endauth
            </header>

            @auth
                @php
                    $tabs = [
                        ['label' => 'Agents', 'route' => 'console.agents.index', 'active' => request()->routeIs('console.agents.*') && ! request()->routeIs('console.agents.instructions.*')],
                        ['label' => 'Files', 'route' => 'console.files.index', 'active' => request()->routeIs('console.files.*')],
                        ['label' => 'Shared', 'route' => 'console.shared.index', 'active' => request()->routeIs('console.shared.*')],
                        ['label' => 'Instructions', 'route' => 'console.instructions.index', 'active' => request()->routeIs('console.instructions.*') || request()->routeIs('console.agents.instructions.*')],
                    ];
                @endphp
                <nav class="mb-8 flex gap-1 border border-border bg-panel p-1" style="border-radius: var(--radius-console)">
                    @foreach ($tabs as $tab)
                        <a href="{{ route($tab['route']) }}"
                            class="px-3 py-1.5 font-mono text-xs tracking-[0.12em] {{ $tab['active'] ? 'border border-amber bg-void text-amber' : 'border border-transparent text-steel hover:text-cream' }}"
                            style="border-radius: var(--radius-console)">
                            {{ strtoupper($tab['label']) }}
                        </a>
                    @endforeach
                </nav>
            @endauth

            {{ $slot }}
        </div>
    </body>
</html>
