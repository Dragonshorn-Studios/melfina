<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Mcp\ToolCatalog;
use App\Models\Agent;
use App\Models\AgentEnrollment;
use App\Rules\UnreservedAgentName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The owner decision over a self-enrollment report: accept binds the
 * runtime's claimed name to an agent (found by name, or created) under a
 * chosen scope allowlist; revoke/decline kills future mints plus every
 * key already minted from the enrollment.
 */
class EnrollmentController extends Controller
{
    /**
     * Accept a pending report. The claimed name becomes (or attaches to)
     * an agent with no `melf_…` key of its own when created — enrollment
     * agents authenticate only through minted scoped keys.
     */
    public function accept(Request $request, AgentEnrollment $enrollment, ToolCatalog $catalog): RedirectResponse
    {
        if (! $enrollment->isPending()) {
            throw ValidationException::withMessages([
                'enrollment' => 'This enrollment was already decided.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', new UnreservedAgentName],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in($catalog->scopeChoices())],
        ]);

        DB::transaction(function () use ($enrollment, $validated): void {
            // A claimed name that already exists binds the enrollment to
            // that brain — the owner sees the match in the accept list.
            $agent = Agent::query()->where('name', $validated['name'])->first()
                ?? Agent::query()->create(['name' => $validated['name']]);

            $enrollment->accept($agent, $validated['scopes']);
        });

        return redirect()
            ->route('console.agents.index')
            ->with('status', "Enrollment '{$enrollment->remote_id}' accepted.");
    }

    /**
     * Revoke acceptance, or decline a pending report — same endpoint;
     * accepting again isn't possible once decided.
     */
    public function revoke(AgentEnrollment $enrollment): RedirectResponse
    {
        DB::transaction(fn () => $enrollment->revoke());

        return redirect()
            ->route('console.agents.index')
            ->with('status', "Enrollment '{$enrollment->remote_id}' revoked.");
    }
}
