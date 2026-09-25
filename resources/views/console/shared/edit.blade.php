<x-console-layout :title="'ACL '.$space->name">
    <main class="border border-border bg-panel p-6 shadow-[inset_0_1px_0_0_rgba(232,168,56,0.08)]" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">SHARED SPACE</p>
        <h1 class="mt-2 text-xl font-semibold tracking-tight text-cream">{{ $space->name }}</h1>
        <p class="mt-2 text-sm text-steel">write implies read. Agents without a grant can't see this space at all.</p>

        <form method="POST" action="{{ route('console.shared.update', $space) }}" class="mt-6 space-y-5">
            @csrf
            @method('PUT')
            <div>
                <label for="name" class="block font-mono text-xs text-steel">name</label>
                <input id="name" name="name" type="text" value="{{ old('name', $space->name) }}" required
                    class="mt-1 w-full border border-border bg-void px-3 py-2 font-mono text-sm text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                @error('name') <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <p class="block font-mono text-xs text-steel">acl</p>
                @if ($agents->isEmpty())
                    <p class="mt-2 text-sm text-steel">No agents yet — create one first.</p>
                @else
                    <table class="mt-2 w-full border-collapse font-mono text-xs">
                        <thead>
                            <tr class="border-b border-border text-left text-steel">
                                <th class="py-2 pr-4">agent</th>
                                <th class="py-2">grant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($agents as $agent)
                                @php($current = old('acl.'.$agent->id, $grants[$agent->id]->permission ?? 'none'))
                                <tr class="border-b border-border/50">
                                    <td class="py-2 pr-4 text-cream">{{ $agent->name }}</td>
                                    <td class="py-2">
                                        <select name="acl[{{ $agent->id }}]"
                                            class="border border-border bg-void px-2 py-1 font-mono text-xs text-cream outline-none focus:border-amber" style="border-radius: var(--radius-console)">
                                            <option value="none" @selected($current === 'none')>none</option>
                                            <option value="read" @selected($current === 'read')>read</option>
                                            <option value="write" @selected($current === 'write')>write</option>
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <button type="submit"
                class="border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                save
            </button>
            <a href="{{ route('console.shared.index') }}" class="ml-3 font-mono text-xs text-steel underline decoration-border underline-offset-4">back</a>
        </form>
    </main>
</x-console-layout>
