<?php

namespace App\Providers;

use App\Models\DeviceAlert;
use App\Models\User;
use App\Services\EloquentDeviceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind our Eloquent-backed provider as the singleton that the package
        // resolves under the 'hikvision.device.provider' key.
        // Using Eloquent (instead of DB::table) ensures the 'encrypted' cast
        // on Terminal::$password is applied, so the real plaintext password
        // reaches the ISAPI client rather than the raw cipher-text.
        $this->app->singleton(EloquentDeviceProvider::class);

        $this->app->singleton('hikvision.device.provider', function ($app) {
            return $app->make(EloquentDeviceProvider::class);
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (
            $this->app->environment('production') ||
            str_starts_with((string) config('app.url'), 'https://') ||
            request()->isSecure() ||
            request()->header('X-Forwarded-Proto') === 'https'
        ) {
            URL::forceScheme('https');
        }

        $this->defineDevicePermissions();
        $this->defineAccessPermissions();
        $this->defineRateLimits();

        // Unacknowledged device alerts are shown in the sidebar on every page.
        View::composer('layouts.app', function ($view): void {
            $view->with(
                'openDeviceAlertCount',
                auth()->check() ? DeviceAlert::query()->unacknowledged()->count() : 0,
            );
        });
    }

    /**
     * Device-management permissions.
     *
     * Every existing account is an administrator, so installing this change
     * grants and removes nothing. Accounts deliberately set to `operator` can
     * view devices and run read-only diagnostics; only administrators can
     * refresh, reconnect, change monitoring settings or acknowledge alerts.
     */
    private function defineDevicePermissions(): void
    {
        Gate::define('devices.view', fn (User $user): bool => true);

        Gate::define('devices.diagnostics', fn (User $user): bool => $user->canOperateDevices());

        Gate::define('devices.manage', fn (User $user): bool => $user->isAdmin());

        Gate::define('devices.alerts.acknowledge', fn (User $user): bool => $user->canOperateDevices());
    }

    /**
     * Access-control permissions.
     *
     * Viewing who holds which door is part of the monitoring role every
     * account already has. Granting, revoking, suspending and enrolling are
     * administrator-only: they change what a physical door will open.
     */
    private function defineAccessPermissions(): void
    {
        Gate::define('access.view', fn (User $user): bool => true);

        Gate::define('access.manage', fn (User $user): bool => $user->isAdmin());

        Gate::define('access.enroll', fn (User $user): bool => $user->isAdmin());

        Gate::define('access.audit', fn (User $user): bool => $user->isAdmin());
    }

    private function defineRateLimits(): void
    {
        RateLimiter::for('device-refresh', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('device-diagnostics', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('device-poll', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
