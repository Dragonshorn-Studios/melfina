<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Instruction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InstructionController extends Controller
{
    /**
     * Instruction editor landing: pick an agent to shape.
     */
    public function index(): View
    {
        $this->authorize('viewAny', Agent::class);

        return view('console.instructions.index', [
            'agents' => Agent::query()->withExists('instruction')->orderBy('name')->get(),
        ]);
    }

    /**
     * Edit the (1:1) instruction record for an agent, creating it lazily.
     */
    public function edit(Agent $agent): View
    {
        $instruction = $agent->instruction ?? new Instruction(['agent_id' => $agent->id]);

        if ($instruction->exists) {
            $this->authorize('update', $instruction);
        } else {
            $this->authorize('create', Instruction::class);
        }

        return view('console.instructions.edit', [
            'agent' => $agent,
            'instruction' => $instruction,
        ]);
    }

    /**
     * Upsert owner content + ordered sub-layers. Empty layer entries are
     * dropped — clearing a layer deletes it.
     */
    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:100000'],
            'layers' => ['nullable', 'array', 'max:50'],
            'layers.*' => ['nullable', 'string', 'max:10000'],
        ]);

        $instruction = $agent->instruction ?? new Instruction(['agent_id' => $agent->id]);
        $this->authorize($instruction->exists ? 'update' : 'create', $instruction);

        $layers = collect($validated['layers'] ?? [])
            ->map(fn (?string $layer) => trim((string) $layer))
            ->filter()
            ->values()
            ->all();

        $instruction->fill([
            'content' => $validated['content'] ?? '',
            'layers' => $layers === [] ? null : $layers,
        ])->save();

        return redirect()->route('console.agents.instructions.edit', $agent);
    }
}
