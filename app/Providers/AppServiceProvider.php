<?php

namespace App\Providers;

use App\Mcp\Auth\AttachTokenAgent;
use App\Mcp\Auth\RenderMcpAuthorizationPage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::tokensExpireIn(now()->addHours(2));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::setDefaultScope(['mcp:use']);
        Passport::$deviceCodeGrantEnabled = false;

        Passport::authorizationView(
            fn (array $parameters) => app(RenderMcpAuthorizationPage::class)->handle($parameters),
        );

        if (env('PASSPORT_KEYS_DIR')) {
            Passport::loadKeysFrom((string) env('PASSPORT_KEYS_DIR'));
        }

        Event::listen(AccessTokenCreated::class, AttachTokenAgent::class);
        // QueueMailPing needs no registration — app/Listeners is auto-discovered.
    }
}
