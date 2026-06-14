<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * In local environment, all access is permitted.
     * In production, only users authenticated via the 'saas' guard can access.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            if (app()->environment('local')) {
                return true;
            }

            // In non-local environments, require the 'saas' guard to be authenticated.
            // The 'saas' guard uses the SuperAdmin model (super_admins provider).
            $saasUser = auth()->guard('saas')->user();

            if ($saasUser !== null) {
                return true;
            }

            // Fallback: allow regular ADMIN users authenticated via the default guard.
            return $user !== null && isset($user->role) && $user->role === 'ADMIN';
        });
    }
}
