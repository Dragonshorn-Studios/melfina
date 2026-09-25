<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Authorize {{ $client->name }} — Melfina</title>
        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-void text-cream antialiased">
        <div class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
            <div class="border border-border bg-panel" style="border-radius: var(--radius-console)">
                <div class="space-y-1.5 p-6 pb-4">
                    <div class="mb-4 flex items-center justify-center gap-3">
                        <svg class="h-8 w-8 shrink-0 text-amber" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                            <path d="M24 4 L28 16 L44 20 L28 24 L24 44 L20 24 L4 20 L20 16 Z" stroke="currentColor" stroke-width="2" fill="none"/>
                            <circle cx="24" cy="20" r="3" fill="currentColor"/>
                        </svg>
                    </div>
                    <h1 class="text-center font-mono text-lg tracking-[0.15em] text-cream">
                        AUTHORIZE {{ strtoupper($client->name) }}
                    </h1>
                    <p class="text-center font-mono text-xs text-steel">
                        This client gets full read/write access to one agent's brain — its files, instructions, and shared folders it can reach.
                    </p>
                </div>

                <div class="space-y-4 p-6 pt-0">
                    <div class="border border-border bg-void p-4" style="border-radius: var(--radius-console)">
                        <p class="mb-1 font-mono text-xs text-steel">Signed in as</p>
                        <p class="font-mono text-sm text-cream">{{ $user->email }}</p>
                    </div>

                    @if ($errors->any())
                        <div class="space-y-1 border border-amber bg-void p-4" style="border-radius: var(--radius-console)">
                            @foreach ($errors->all() as $error)
                                <p class="font-mono text-xs text-amber">{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    @if ($agents->isEmpty())
                        <p class="font-mono text-xs text-steel">
                            No agents exist yet. Create one in the console before authorizing a client.
                        </p>
                    @endif
                </div>

                <div class="flex items-center gap-3 p-6 pt-0">
                    <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="state" value="">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <button type="submit" class="w-full border border-border bg-void px-4 py-2 font-mono text-xs tracking-[0.12em] text-steel hover:text-cream" style="border-radius: var(--radius-console)">
                            CANCEL
                        </button>
                    </form>

                    <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                        @csrf
                        <input type="hidden" name="state" value="">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">

                        @if ($agents->isNotEmpty())
                            <div class="mb-3 space-y-2">
                                <p class="font-mono text-xs tracking-[0.12em] text-steel">ACT AS AGENT</p>
                                @foreach ($agents as $agent)
                                    <label class="flex cursor-pointer items-center gap-2 border border-border bg-void px-3 py-2 font-mono text-xs text-cream hover:border-amber" style="border-radius: var(--radius-console)">
                                        <input type="radio" name="agent_id" value="{{ $agent->id }}"
                                            @checked($selectedAgentId === $agent->id || ($selectedAgentId === null && $agents->count() === 1))
                                            class="accent-amber" required>
                                        {{ $agent->name }}
                                    </label>
                                @endforeach
                            </div>
                            <button type="submit" class="w-full border border-amber bg-void px-4 py-2 font-mono text-xs tracking-[0.12em] text-amber hover:bg-panel" style="border-radius: var(--radius-console)">
                                AUTHORIZE
                            </button>
                        @endif
                    </form>
                </div>
            </div>
        </div>
    </body>
</html>
