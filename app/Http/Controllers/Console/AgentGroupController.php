<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\KnowledgeEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgentGroupController extends Controller
{
    public function index(): View
    {
        return view('console.groups.index', [
            'groups' => AgentGroup::query()->with('mainPerson')->withCount('agents')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('console.groups.edit', [
            'group' => null,
            'agents' => Agent::query()->orderBy('name')->get(),
            'members' => [],
            'people' => KnowledgeEntry::query()->where('type', 'person')->orderBy('name_lower')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = $this->save($request, new AgentGroup);

        return redirect()
            ->route('console.groups.edit', $group)
            ->with('status', 'Group created.');
    }

    public function edit(AgentGroup $group): View
    {
        return view('console.groups.edit', [
            'group' => $group,
            'agents' => Agent::query()->orderBy('name')->get(),
            'members' => $group->agents()->pluck('agents.id')->all(),
            'people' => KnowledgeEntry::query()->where('type', 'person')->orderBy('name_lower')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, AgentGroup $group): RedirectResponse
    {
        $this->save($request, $group);

        return redirect()
            ->route('console.groups.edit', $group)
            ->with('status', 'Group saved.');
    }

    public function destroy(AgentGroup $group): RedirectResponse
    {
        $name = $group->name;
        $group->delete();

        return redirect()
            ->route('console.groups.index')
            ->with('status', "Group '{$name}' deleted.");
    }

    private function save(Request $request, AgentGroup $group): AgentGroup
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('agent_groups', 'name')->ignore($group->id)],
            'preamble' => ['nullable', 'string', 'max:20000'],
            'main_person_entry_id' => ['nullable', 'integer', 'exists:knowledge_entries,id'],
            'agents' => ['nullable', 'array'],
            'agents.*' => ['integer', 'exists:agents,id'],
        ]);

        $personId = $validated['main_person_entry_id'] ?? null;
        if ($personId !== null && KnowledgeEntry::query()->whereKey($personId)->where('type', 'person')->doesntExist()) {
            throw ValidationException::withMessages([
                'main_person_entry_id' => 'The shared main person must be a person entry.',
            ]);
        }

        $group->fill([
            'name' => trim($validated['name']),
            'preamble' => $validated['preamble'] ?: null,
            'main_person_entry_id' => $personId,
        ]);
        $group->save();
        $group->agents()->sync($validated['agents'] ?? []);

        return $group;
    }
}
