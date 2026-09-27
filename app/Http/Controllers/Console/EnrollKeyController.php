<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\AgentEnrollKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Owner management of named enrollment keys (`melfe_…`) — the "global
 * secret" a secret-less runtime presents to POST /api/enroll. Mirrors
 * fleet-key handling: issue rolls same-name keys, revoke kills one key
 * only, plaintext is flashed once.
 */
class EnrollKeyController extends Controller
{
    /**
     * Create or roll a named enroll key: same-name live keys are revoked
     * first, so reusing a name IS the roll. The plaintext is flashed once.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enroll_name' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ]);
        $name = $validated['enroll_name'] ?? null;
        $name = $name !== null && $name !== '' ? $name : 'default';

        $user = $request->user();
        $user->agentEnrollKeys()->where('name', $name)->whereNull('revoked_at')->get()
            ->each(fn (AgentEnrollKey $key) => $key->revoke());

        $plaintext = AgentEnrollKey::issue($user, $name);

        return redirect()
            ->route('console.dashboard')
            ->with('plaintext_enroll_key', $plaintext)
            ->with('plaintext_enroll_key_name', $name);
    }

    /**
     * Revoke one named enroll key without touching the others. Pending
     * and accepted enrollments survive — a fresh key just stops new
     * reports and new mints under the leaked credential.
     */
    public function destroy(Request $request, AgentEnrollKey $enrollKey): RedirectResponse
    {
        abort_if($enrollKey->user_id !== $request->user()->id, 404);

        $enrollKey->revoke();

        return redirect()
            ->route('console.dashboard')
            ->with('status', "Enroll key '{$enrollKey->name}' revoked.");
    }
}
