<x-console-layout :title="'Owner bootstrap'">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">FIRST-OWNER BOOTSTRAP</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">Create the owner account</h1>
        <p class="mt-2 text-sm leading-relaxed text-steel">
            One owner per install. Registration closes once this account exists.
        </p>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="name" class="block font-mono text-xs text-steel">name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('name') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="block font-mono text-xs text-steel">email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('email') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="block font-mono text-xs text-steel">password</label>
                <input id="password" name="password" type="password" required
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('password') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation" class="block font-mono text-xs text-steel">confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
            </div>
            <button type="submit"
                class="w-full border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                create owner
            </button>
        </form>
    </main>
</x-console-layout>
