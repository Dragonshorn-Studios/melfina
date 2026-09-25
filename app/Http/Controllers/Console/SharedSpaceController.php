<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\SharedAcl;
use App\Models\SharedSpace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SharedSpaceController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', SharedSpace::class);

        return view('console.shared.index', [
            'spaces' => SharedSpace::query()->withCount(['aclEntries', 'files'])->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', SharedSpace::class);

        return view('console.shared.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SharedSpace::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:shared_spaces,name'],
        ]);

        $space = SharedSpace::query()->create($validated);

        return redirect()->route('console.shared.edit', $space);
    }

    public function edit(SharedSpace $shared): View
    {
        $this->authorize('update', $shared);

        $grants = $shared->aclEntries()->get()->keyBy('agent_id');

        return view('console.shared.edit', [
            'space' => $shared,
            'agents' => Agent::query()->orderBy('name')->get(),
            'grants' => $grants,
        ]);
    }

    public function update(Request $request, SharedSpace $shared): RedirectResponse
    {
        $this->authorize('update', $shared);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('shared_spaces', 'name')->ignore($shared->id)],
            'acl' => ['nullable', 'array'],
            'acl.*' => ['nullable', Rule::in([SharedAcl::PERMISSION_READ, SharedAcl::PERMISSION_WRITE, 'none'])],
        ]);

        $agentIds = Agent::query()->pluck('id');
        $acl = collect($validated['acl'] ?? [])
            ->only($agentIds->all())
            ->reject(fn (string $permission) => $permission === 'none');

        DB::transaction(function () use ($shared, $validated, $acl): void {
            $shared->update(['name' => $validated['name']]);

            $shared->aclEntries()->whereNotIn('agent_id', $acl->keys()->all())->delete();

            foreach ($acl as $agentId => $permission) {
                $shared->aclEntries()->updateOrCreate(
                    ['agent_id' => $agentId],
                    ['permission' => $permission],
                );
            }
        });

        return redirect()->route('console.shared.edit', $shared);
    }
}
