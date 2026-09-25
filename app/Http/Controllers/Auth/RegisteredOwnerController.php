<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredOwnerController extends Controller
{
    /**
     * Show the first-owner registration form.
     */
    public function create(): View|RedirectResponse
    {
        if (User::query()->where('is_owner', true)->exists()) {
            return redirect()->route('login');
        }

        return view('auth.register');
    }

    /**
     * Bootstrap the single owner account. Registration closes once an owner exists.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            abort_if(User::query()->where('is_owner', true)->lockForUpdate()->exists(), 403);

            return User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'is_owner' => true,
            ]);
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('console.dashboard');
    }
}
