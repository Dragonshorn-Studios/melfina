<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Owner management of admin API keys (`melfa_…`) — the credentials a
 * fleet system such as Yukihime presents to /api/admin. Mirrors the
 * other key families: issue rolls same-name keys, revoke kills one key
 * only, plaintext is flashed once.
 */
class AdminKeyController extends Controller
{
    /**
     * Create or roll a named admin key: same-name live keys are revoked
     * first, so reusing a name IS the roll. The permissions are the
     * key's whole allowlist — what the new consumer may do. The
     * plaintext is flashed once.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'admin_name' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => [Rule::in(AdminApiKey::permissionChoices())],
        ]);
        $name = $validated['admin_name'] ?? null;
        $name = $name !== null && $name !== '' ? $name : 'default';

        $user = $request->user();
        $user->adminApiKeys()->where('name', $name)->whereNull('revoked_at')->get()
            ->each(fn (AdminApiKey $key) => $key->revoke());

        $plaintext = AdminApiKey::issue($user, $name, $validated['permissions']);

        return redirect()
            ->route('console.dashboard')
            ->with('plaintext_admin_key', $plaintext)
            ->with('plaintext_admin_key_name', $name);
    }

    /**
     * Revoke one named admin key without touching the others. Brains it
     * provisioned keep working — only the management credential dies.
     */
    public function destroy(Request $request, AdminApiKey $adminKey): RedirectResponse
    {
        abort_if($adminKey->user_id !== $request->user()->id, 404);

        $adminKey->revoke();

        return redirect()
            ->route('console.dashboard')
            ->with('status', "Admin key '{$adminKey->name}' revoked.");
    }
}
