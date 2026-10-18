<x-console-layout :title="'ACL '.$space->name">
    <x-console-panel>
        <x-page-header eyebrow="SHARED SPACE" title="{{ $space->name }}">
            <x-slot:subtitle>write implies read. A direct agent grant wins over a group grant. The same grant covers the space and its files.</x-slot:subtitle>
        </x-page-header>

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

            @if (($groups ?? collect())->isNotEmpty())
                <div>
                    <p class="block font-mono text-xs text-steel">group acl</p>
                    <table class="mt-2 w-full border-collapse font-mono text-xs">
                        <thead>
                            <tr class="border-b border-border text-left text-steel">
                                <th class="py-2 pr-4">group</th>
                                <th class="py-2">grant</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $group)
                                @php($current = old('group_acl.'.$group->id, $groupGrants[$group->id]->permission ?? 'none'))
                                <tr class="border-b border-border/50">
                                    <td class="py-2 pr-4 text-cream">{{ $group->name }}</td>
                                    <td class="py-2">
                                        <select name="group_acl[{{ $group->id }}]"
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
                </div>
            @endif

            <button type="submit"
                class="border border-amber bg-amber px-4 py-2 font-mono text-sm font-semibold text-void hover:bg-amber-dim" style="border-radius: var(--radius-console)">
                save
            </button>
            <a href="{{ route('console.shared.index') }}" class="ml-3 font-mono text-xs text-steel underline decoration-border underline-offset-4">back</a>
        </form>
    </x-console-panel>
</x-console-layout>
