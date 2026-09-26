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
        <div class="flex min-h-screen flex-col lg:flex-row">
            @guest
                <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col px-6 py-12">
                    <header class="mb-10 flex items-center gap-3 border-b border-border pb-6">
                        <svg class="h-8 w-8 shrink-0 text-amber" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                            <path d="M24 4 L28 16 L44 20 L28 24 L24 44 L20 24 L4 20 L20 16 Z" stroke="currentColor" stroke-width="2" fill="none"/>
                            <circle cx="24" cy="20" r="3" fill="currentColor"/>
                        </svg>
                        <p class="font-mono text-xs tracking-[0.2em] text-amber">MELFINA</p>
                    </header>
                    {{ $slot }}
                </div>
            @else
            <aside class="flex shrink-0 flex-col border-b border-border bg-panel lg:sticky lg:top-0 lg:h-screen lg:w-64 lg:border-b-0 lg:border-r">
                <div class="flex items-center justify-between px-4 py-4 lg:justify-start lg:gap-3">
                    <a href="{{ route('console.dashboard') }}" class="flex items-center gap-3">
                        <svg class="h-7 w-7 shrink-0 text-amber" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                            <path d="M24 4 L28 16 L44 20 L28 24 L24 44 L20 24 L4 20 L20 16 Z" stroke="currentColor" stroke-width="2" fill="none"/>
                            <circle cx="24" cy="20" r="3" fill="currentColor"/>
                        </svg>
                        <span class="font-mono text-xs tracking-[0.2em] text-amber">MELFINA</span>
                    </a>
                    @auth
                        <form method="POST" action="{{ route('logout') }}" class="lg:hidden">
                            @csrf
                            <button type="submit" class="font-mono text-xs text-steel underline decoration-border underline-offset-4 hover:text-amber">
                                logout
                            </button>
                        </form>
                    @endauth
                </div>

                <nav class="flex-1 overflow-x-auto px-3 pb-2 lg:overflow-y-auto lg:overflow-x-visible lg:pb-0">
                    <p class="px-1 font-mono text-[10px] tracking-[0.2em] text-steel">AGENTS</p>
                    <div class="mt-2 flex gap-1 lg:flex-col">
                        @forelse ($agents as $entry)
                            @php
                                $current = $agent?->is($entry);
                            @endphp
                            <a href="{{ route('console.files.show', $entry) }}"
                                class="flex shrink-0 items-center justify-between gap-3 border px-3 py-1.5 font-mono text-xs {{ $current ? 'border-amber bg-void text-amber' : 'border-transparent text-steel hover:border-border hover:text-cream' }}"
                                style="border-radius: var(--radius-console)">
                                <span class="truncate">{{ $entry->name }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="{{ $entry->isActive() ? 'text-amber' : 'text-danger' }}" title="{{ $entry->status }}">●</span>
                                    <span class="text-[10px] {{ $current ? 'text-amber' : 'text-steel' }}">{{ $entry->files_count }}</span>
                                </span>
                            </a>
                        @empty
                            <p class="px-1 py-1.5 font-mono text-xs text-steel">No agents yet.</p>
                        @endforelse
                        <a href="{{ route('console.agents.create') }}"
                            class="shrink-0 border border-dashed border-border px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-amber"
                            style="border-radius: var(--radius-console)">+ new agent</a>
                    </div>
                </nav>

                <div class="border-t border-border px-3 py-3">
                    <p class="px-1 font-mono text-[10px] tracking-[0.2em] text-steel">SECTIONS</p>
                    <div class="mt-2 flex gap-1 lg:flex-col">
                        <a href="{{ route('console.agents.index') }}"
                            class="border px-3 py-1.5 font-mono text-xs {{ request()->routeIs('console.agents.*') && ! request()->routeIs('console.agents.instructions.*') ? 'border-amber bg-void text-amber' : 'border-transparent text-steel hover:border-border hover:text-cream' }}"
                            style="border-radius: var(--radius-console)">agents</a>
                        <a href="{{ route('console.shared.index') }}"
                            class="border px-3 py-1.5 font-mono text-xs {{ request()->routeIs('console.shared.*') ? 'border-amber bg-void text-amber' : 'border-transparent text-steel hover:border-border hover:text-cream' }}"
                            style="border-radius: var(--radius-console)">shared</a>
                        <a href="{{ route('console.messages.index') }}"
                            class="border px-3 py-1.5 font-mono text-xs {{ request()->routeIs('console.messages.*') ? 'border-amber bg-void text-amber' : 'border-transparent text-steel hover:border-border hover:text-cream' }}"
                            style="border-radius: var(--radius-console)">{{ \App\Models\AgentMessage::messagesLabel() }}</a>
                        <a href="{{ route('console.mcp.connect') }}"
                            class="border px-3 py-1.5 font-mono text-xs {{ request()->routeIs('console.mcp.*') ? 'border-amber bg-void text-amber' : 'border-transparent text-steel hover:border-border hover:text-cream' }}"
                            style="border-radius: var(--radius-console)">mcp</a>
                        <a href="{{ route('console.clients.index') }}"
                            class="border px-3 py-1.5 font-mono text-xs {{ request()->routeIs('console.clients.*') ? 'border-amber bg-void text-amber' : 'border-transparent text-steel hover:border-border hover:text-cream' }}"
                            style="border-radius: var(--radius-console)">clients</a>
                    </div>
                    @auth
                        <form method="POST" action="{{ route('logout') }}" class="mt-4 hidden lg:block">
                            @csrf
                            <button type="submit" class="px-1 font-mono text-xs text-steel underline decoration-border underline-offset-4 hover:text-amber">
                                logout
                            </button>
                        </form>
                    @endauth
                </div>
            </aside>

            <div class="mx-auto flex w-full max-w-3xl flex-1 flex-col px-6 py-8 lg:py-12">
                @if ($agent)
                    @php
                        $agentTabs = [
                            ['label' => 'Files', 'route' => route('console.files.show', $agent), 'active' => request()->routeIs('console.files.*')],
                            ['label' => 'Instructions', 'route' => route('console.agents.instructions.edit', $agent), 'active' => request()->routeIs('console.agents.instructions.*')],
                            ['label' => 'Key', 'route' => route('console.agents.show', $agent), 'active' => request()->routeIs('console.agents.show') || request()->routeIs('console.agents.edit')],
                            ['label' => 'Connect', 'route' => route('console.agents.connect', $agent), 'active' => request()->routeIs('console.agents.connect')],
                        ];
                    @endphp
                    <nav class="mb-8 flex gap-1 border border-border bg-panel p-1" style="border-radius: var(--radius-console)">
                        @foreach ($agentTabs as $tab)
                            <a href="{{ $tab['route'] }}"
                                class="px-3 py-1.5 font-mono text-xs tracking-[0.12em] {{ $tab['active'] ? 'border border-amber bg-void text-amber' : 'border border-transparent text-steel hover:text-cream' }}"
                                style="border-radius: var(--radius-console)">
                                {{ strtoupper($tab['label']) }}
                            </a>
                        @endforeach
                    </nav>
                @endif

                {{ $slot }}
            </div>
            @endguest
        </div>

        @auth
            {{-- m-auto restores the UA's margin:auto dialog centering that
                 the Tailwind preflight margin reset strips away. --}}
            <dialog id="melfina-confirm"
                class="m-auto w-[min(24rem,90vw)] border border-border bg-panel p-0 text-cream backdrop:bg-void/80"
                style="border-radius: var(--radius-console)">
                <form method="dialog" class="p-5">
                    <p class="font-mono text-xs tracking-[0.16em] text-amber">CONFIRM</p>
                    <p id="melfina-confirm-message" class="mt-3 text-sm text-steel"></p>
                    <div class="mt-5 flex justify-end gap-2">
                        <button value="cancel"
                            class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-steel hover:border-steel hover:text-cream"
                            style="border-radius: var(--radius-console)">cancel</button>
                        <button id="melfina-confirm-yes" value="default"
                            class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                            style="border-radius: var(--radius-console)">confirm</button>
                    </div>
                </form>
            </dialog>
        @endauth
    </body>
</html>
