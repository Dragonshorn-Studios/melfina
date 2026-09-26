<?php

use App\Models\User;

it('shows the first-owner registration form when no owner exists', function () {
    $this->get(route('register'))->assertOk();
});

it('bootstraps the first owner and logs them in', function () {
    $this->post(route('register'), [
        'name' => 'Boss Lady',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('console.dashboard'));

    $owner = User::sole();
    expect($owner->is_owner)->toBeTrue()
        ->and($owner->email)->toBe('owner@example.com');

    $this->assertAuthenticatedAs($owner);
});

it('closes registration once an owner exists', function () {
    User::factory()->owner()->create();

    $this->get(route('register'))->assertNotFound();

    $this->post(route('register'), [
        'name' => 'Second',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::count())->toBe(1);
});

it('redirects guests away from the owner console', function () {
    $this->get(route('console.dashboard'))->assertRedirect(route('login'));

    // The shared confirm dialog is owner chrome — guests never see it.
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('id="melfina-confirm"', false);
});

it('lets the owner into the console', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('console.dashboard'))
        ->assertOk()
        ->assertSee('id="melfina-confirm"', false)
        ->assertSee('data-confirm="Issue a fleet key under this name?', false)
        ->assertSee('onsubmit="return window.__melfinaConfirm', false)
        ->assertSee('lg:sticky', false);
});

it('forbids non-owner users from owner routes', function () {
    $user = User::factory()->create(['is_owner' => false]);

    $this->actingAs($user)
        ->get(route('console.dashboard'))
        ->assertForbidden();
});

it('logs in with valid credentials', function () {
    $owner = User::factory()->owner()->create([
        'email' => 'owner@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->post(route('login'), [
        'email' => 'owner@example.com',
        'password' => 'password',
    ])->assertRedirect(route('console.dashboard'));

    $this->assertAuthenticatedAs($owner);
});

it('rejects invalid credentials', function () {
    User::factory()->owner()->create([
        'email' => 'owner@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->from(route('login'))->post(route('login'), [
        'email' => 'owner@example.com',
        'password' => 'wrong-password',
    ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('flags the session cookie secure behind a trusted TLS proxy', function () {
    User::factory()->owner()->create([
        'email' => 'owner@example.com',
        'password' => bcrypt('password'),
    ]);

    $response = $this->post(route('login'), [
        'email' => 'owner@example.com',
        'password' => 'password',
    ], ['X-Forwarded-Proto' => 'https']);

    $sessionCookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($sessionCookie)->not->toBeNull()
        ->and($sessionCookie->isSecure())->toBeTrue();
});

it('logs out', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
