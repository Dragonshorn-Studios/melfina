<x-console-layout :title="$agent->name" :agent="$agent">
    <x-console-panel>
        <x-page-header eyebrow="ABOUT" title="{{ $agent->name }}">
            <x-slot:actions>
<span class="font-mono text-xs {{ $agent->isActive() ? 'text-amber' : 'text-danger' }}">{{ $agent->status }}</span>
            </x-slot:actions>
        </x-page-header>

        @if (session('status'))
            <p class="mt-3 font-mono text-xs text-amber">{{ session('status') }}</p>
        @endif

        <dl class="mt-6 space-y-2 font-mono text-xs">
            <div class="flex gap-4">
                <dt class="w-28 text-steel">description</dt>
                <dd class="text-cream">{{ $agent->description ?: '—' }}</dd>
            </div>
            <div class="flex gap-4">
                <dt class="w-28 text-steel">created</dt>
                <dd class="text-cream">{{ $agent->created_at->toDateTimeString() }}</dd>
            </div>
        </dl>

        <div class="mt-6 border-t border-border pt-4">
            <div class="flex items-center justify-between">
                <p class="font-mono text-xs tracking-[0.16em] text-steel">PERSONA &amp; MEMORY</p>
                <a href="{{ route('console.agents.persona.edit', $agent) }}"
                    class="font-mono text-xs text-amber underline decoration-border underline-offset-4">persona tab →</a>
            </div>
            <p class="mt-2 font-mono text-xs text-steel">
                @if ($agent->persona)
                    {{ Str::limit($agent->persona, 120) }}
                @else
                    nothing yet — the imp writes these itself via update_persona.
                @endif
            </p>
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">SCOPED API KEYS</p>
            <p class="mt-1 font-mono text-xs text-steel">Least-privilege <span class="text-cream">melfs_…</span> keys for agents calling the HTTP tool fallback (POST /api/tools/…). A key can only call tools inside its scopes.</p>

            @if ($apiKeys->isNotEmpty())
                <ul class="mt-3 space-y-1 font-mono text-xs">
                    @foreach ($apiKeys as $key)
                        <li class="flex items-center justify-between gap-3 border border-border/50 bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                            <div>
                                <span class="text-cream">{{ $key->name }}</span>
                                <span class="text-steel"> — {{ implode(', ', $key->scopes ?? []) }}</span>
                                @if ($key->expires_at)
                                    <span class="{{ $key->isLive() ? 'text-steel' : 'text-danger' }}"> · {{ $key->expires_at->isPast() ? 'expired' : 'expires' }} {{ $key->expires_at->diffForHumans() }}</span>
                                @endif
                            </div>
                            <x-confirm-form action="{{ route('console.agents.api-keys.destroy', [$agent, $key]) }}" method="DELETE" confirm="Revoke this key? It stops working immediately.">
                                <button type="submit" class="text-steel underline decoration-border underline-offset-4 hover:text-danger">revoke</button>
                            </x-confirm-form>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 font-mono text-xs text-steel">none issued.</p>
            @endif

            @if (session('plaintext_key') && str_starts_with(session('plaintext_key'), 'melfs_'))
                <x-key-banner id="plaintext-key" class="mt-3"
                    label="SCOPED API KEY — SHOWN ONCE"
                    :value="session('plaintext_key')">
                    <x-slot:hint>Copy it now. Only the hash is stored; we cannot show it again.</x-slot:hint>
                </x-key-banner>
            @endif

            <button type="button" onclick="document.getElementById('issue-key-modal').showModal()"
                class="mt-3 border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                style="border-radius: var(--radius-console)">issue key…</button>
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">MCP KEY</p>
            <p class="mt-1 font-mono text-xs text-steel">The <span class="text-cream">melf_…</span> key this agent presents as Bearer or <code>X-Melfina-Agent-Key</code>. Stored only as a hash — the plaintext is shown once, right here, after a rotate.</p>
            @if (session('plaintext_key') && ! str_starts_with(session('plaintext_key'), 'melfs_'))
                <x-key-banner id="plaintext-key" class="mt-3"
                    label="AGENT KEY — SHOWN ONCE"
                    :value="session('plaintext_key')">
                    <x-slot:hint>Copy it now. Only the hash is stored; we cannot show it again.</x-slot:hint>
                </x-key-banner>
            @endif
            <div class="mt-3 flex items-center gap-3">
                <span class="font-mono text-xs text-steel">status: <span class="text-cream">{{ $agent->key_hash ? 'issued (hashed)' : 'none' }}</span></span>
                <x-confirm-form action="{{ route('console.agents.rotate', $agent) }}" confirm="Rotate key? The old key stops working immediately.">
                    <button type="submit"
                        class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                        style="border-radius: var(--radius-console)">rotate key</button>
                </x-confirm-form>
            </div>
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">MAIN PERSON</p>
            <p class="mt-1 font-mono text-xs text-steel">The person entry this agent handles. Leave empty to inherit a single group choice, then the installation operator. Lookup still reports when that person and the operator are the same being.</p>
            <form method="POST" action="{{ route('console.agents.main-person.store', $agent) }}" class="mt-3 space-y-3">
                @csrf
                @method('PUT')
                <select name="main_person_entry_id"
                    class="w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                    <option value="">inherit</option>
                    @foreach ($people ?? [] as $person)
                        <option value="{{ $person->id }}" @selected((string) old('main_person_entry_id', $agent->main_person_entry_id) === (string) $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
                @if (($groups ?? collect())->isNotEmpty())
                    <div class="space-y-1 font-mono text-xs text-cream">
                        @foreach ($groups as $group)
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="groups[]" value="{{ $group->id }}" class="accent-amber" @checked(collect(old('groups', $agent->groups->pluck('id')->all()))->contains($group->id))>
                                {{ $group->name }}
                            </label>
                        @endforeach
                    </div>
                @else
                    <p class="font-mono text-xs text-steel">No groups yet. <a class="text-amber underline decoration-border underline-offset-4" href="{{ route('console.groups.create') }}">Create one</a> to share a preamble and a main person.</p>
                @endif
                <button type="submit"
                    class="border border-border bg-void px-3 py-1 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">save main person</button>
            </form>
            @error('main_person_entry_id')<p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>@enderror
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">CONTEXTUAL REFERENCES</p>
            <p class="mt-1 font-mono text-xs text-steel">A title only this agent uses. It resolves to one entity and does not change instruction text.</p>
            @if (($entityReferences ?? collect())->isNotEmpty())
                <ul class="mt-3 space-y-1 font-mono text-xs">
                    @foreach ($entityReferences as $reference)
                        <li class="flex items-center justify-between gap-3 border border-border/50 bg-void/60 px-3 py-1.5" style="border-radius: var(--radius-console)">
                            <span class="text-cream">{{ $reference->value }} → {{ $reference->entry?->name }} <span class="text-steel">({{ $reference->kind->value }})</span></span>
                            <x-confirm-form action="{{ route('console.agents.references.destroy', [$agent, $reference]) }}" method="DELETE" confirm="Remove this title for {{ $agent->name }}?">
                                <button type="submit" class="text-steel underline decoration-border underline-offset-4 hover:text-danger">remove</button>
                            </x-confirm-form>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('console.agents.references.store', $agent) }}" class="mt-3 flex flex-wrap gap-2">
                @csrf
                <input type="text" name="value" placeholder="title this imp uses" maxlength="200"
                    class="min-w-0 flex-1 border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                <select name="entry_id"
                    class="border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                    @foreach ($people ?? [] as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
                <select name="kind"
                    class="border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                    @foreach ($referenceKinds ?? [] as $kind)
                        <option value="{{ $kind }}">{{ $kind }}</option>
                    @endforeach
                </select>
                <button type="submit"
                    class="border border-border bg-void px-3 py-1 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">bind</button>
            </form>
            @error('value')<p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>@enderror
        </div>

        <div class="mt-6 border-t border-border pt-4">
            <p class="font-mono text-xs tracking-[0.16em] text-steel">ADDRESSING PREFERENCE</p>
            <p class="mt-1 font-mono text-xs text-steel">Fallback only when no instruction exists. Never appended as a layer; never overrides “Always address user as …”.</p>
            @if (($addressingPreferences ?? collect())->isNotEmpty())
                <ul class="mt-3 space-y-1 font-mono text-xs">
                    @foreach ($addressingPreferences as $preference)
                        <li class="text-cream">{{ $preference->person?->name }} · {{ $preference->context }} · {{ $preference->form }}</li>
                    @endforeach
                </ul>
            @endif
            <form method="POST" action="{{ route('console.agents.addressing-preferences.store', $agent) }}" class="mt-3 flex flex-wrap gap-2">
                @csrf
                <select name="person_entry_id"
                    class="border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                    @foreach ($people ?? [] as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </select>
                <input type="text" name="form" placeholder="form of address" maxlength="200"
                    class="min-w-0 flex-1 border border-border bg-void px-2 py-1 font-mono text-xs text-cream"
                    style="border-radius: var(--radius-console)">
                <button type="submit"
                    class="border border-border bg-void px-3 py-1 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">store preference</button>
            </form>
        </div>

        <div class="mt-8 flex items-center gap-3">
            <a href="{{ route('console.agents.edit', $agent) }}"
                class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                style="border-radius: var(--radius-console)">rename / status</a>
            <a href="{{ route('console.agents.index') }}" class="font-mono text-xs text-steel underline decoration-border underline-offset-4">back</a>
        </div>
    </x-console-panel>

    <dialog id="issue-key-modal" class="m-auto w-full max-w-lg border border-border bg-panel p-6 shadow-xl backdrop:bg-void/80" style="border-radius: var(--radius-console)">
        <p class="font-mono text-xs tracking-[0.16em] text-amber-dim">ISSUE SCOPED KEY</p>
        <form method="POST" action="{{ route('console.agents.api-keys.store', $agent) }}" class="mt-4 space-y-4">
            @csrf
            <div>
                <label class="font-mono text-xs text-steel">
                    name
                    <input type="text" name="key_name" required maxlength="60" placeholder="scoped key" value="{{ old('key_name') }}"
                        class="mt-1 block w-full border border-border bg-void px-2 py-1 font-mono text-xs text-cream" style="border-radius: var(--radius-console)">
                </label>
                @error('key_name')
                    <p class="mt-1 font-mono text-xs text-danger">{{ $message }}</p>
                @enderror
            </div>
            <fieldset class="flex flex-wrap gap-x-3 gap-y-1 font-mono text-xs text-steel">
                @foreach ($apiScopes as $scope)
                    <label class="flex items-center gap-1">
                        <input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', []))) class="accent-amber">
                        {{ $scope }}
                        @if ($scope === 'all:read')
                            <span class="opacity-60">(every read scope)</span>
                        @elseif ($scope === 'all:write')
                            <span class="opacity-60">(all read + write)</span>
                        @endif
                    </label>
                @endforeach
            </fieldset>
            @error('scopes')
                <p class="font-mono text-xs text-danger">{{ $message }}</p>
            @enderror
            @foreach ($errors->get('scopes.*') as $scopeErrors)
                <p class="font-mono text-xs text-danger">{{ $scopeErrors[0] }}</p>
            @endforeach
            <div class="flex items-center gap-3">
                <button type="submit"
                    class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                    style="border-radius: var(--radius-console)">issue key</button>
                <button type="button" onclick="document.getElementById('issue-key-modal').close()"
                    class="font-mono text-xs text-steel underline decoration-border underline-offset-4">cancel</button>
            </div>
        </form>
    </dialog>
    @if ($errors->hasAny(['key_name', 'scopes', 'scopes.*']))
        <script>document.getElementById('issue-key-modal').showModal();</script>
    @endif
</x-console-layout>
