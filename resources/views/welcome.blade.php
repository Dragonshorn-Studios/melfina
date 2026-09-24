<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Melfina — navigator-brain</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-void text-cream antialiased">
        <div class="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-6 py-12">
            <header class="mb-10 flex items-center gap-3 border-b border-border pb-6">
                <svg class="h-10 w-10 shrink-0 text-amber" viewBox="0 0 48 48" fill="none" aria-hidden="true">
                    <path d="M24 4 L28 16 L44 20 L28 24 L24 44 L20 24 L4 20 L20 16 Z" stroke="currentColor" stroke-width="2" fill="none"/>
                    <circle cx="24" cy="20" r="3" fill="currentColor"/>
                </svg>
                <div>
                    <p class="font-mono text-xs tracking-[0.2em] text-amber">MELFINA</p>
                    <p class="font-mono text-xs text-steel">Outlaw Star / navigator-brain</p>
                </div>
            </header>

            <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
                <h1 class="text-2xl font-semibold tracking-tight text-cream">
                    Each agent gets a key and a brain. You edit the instructions.
                </h1>
                <p class="mt-4 max-w-prose text-base leading-relaxed text-steel">
                    Self-hosted Imp brain. One agent, one key, one file tree. Log in as owner to shape the notes; the agent reads and writes only its own. Not a shared memory layer — a navigator for each mind.
                </p>
                <p class="mt-4 max-w-prose text-base leading-relaxed text-cream">
                    Shared folders. You set who can read and write. Not mail — that’s ImpPost.
                </p>
            </main>

            <section class="mt-6 border border-border bg-panel p-6" style="border-radius: var(--radius-console)">
                <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">THIS SHELL</p>
                <p class="mt-2 text-sm leading-relaxed text-steel">
                    Laravel modular monolith boot. SQLite. Blade + Tailwind + CSS variables.
                    Owner auth, agents, VFS, MCP, and Shared ACL are not here yet.
                    Future chrome (mention only): Agents · Files · Shared · Instructions.
                </p>
                <p class="mt-4 font-mono text-xs leading-relaxed text-steel">
                    Not Mem0. Not ImpPost-mail. Not Melina. Not waifu chat.
                </p>
            </section>

            <footer class="mt-8 font-mono text-xs text-steel">
                Read <span class="text-amber">docs/</span> in this repo — do not scrape AFFiNE.
            </footer>
        </div>
    </body>
</html>
