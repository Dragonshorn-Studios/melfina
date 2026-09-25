<x-console-layout :title="'Owner login'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">OWNER CONSOLE</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Log in as owner</h1>
        <p class="mt-2 text-sm leading-relaxed text-steel">
            Agents use Bearer/MCP keys — never this session.
        </p>

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="block font-mono text-xs text-steel">email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('email') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="block font-mono text-xs text-steel">password</label>
                <input id="password" name="password" type="password" required
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('password') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 font-mono text-xs text-steel">
                <input type="checkbox" name="remember" class="border-border bg-void text-amber">
                remember me
            </label>
            <button type="submit"
                class="w-full border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                log in
            </button>
        </form>
    </main>
</x-console-layout>
