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

        $this->defineRoleHierarchy();
        $this->defineDevicePermissions();
        $this->defineAccessPermissions();
        $this->definePersonnelPermissions();
        $this->defineDashboardPermissions();
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
     * The Systems Engineer and Super Admin have unconditional full access
     * to every gate, policy, and module across the entire suite.
     */
    private function defineRoleHierarchy(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->isFullAdmin()) {
                return true;
            }

            return null; // Fall through to standard gate checks
        });
    }

    /**
     * Device-management permissions.
     */
    private function defineDevicePermissions(): void
    {
        Gate::define('devices.view', fn (User $user): bool => true);

        Gate::define('devices.diagnostics', fn (User $user): bool => $user->canOperateDevices());

        Gate::define('devices.manage', fn (User $user): bool => $user->isFullAdmin());

        Gate::define('devices.alerts.acknowledge', fn (User $user): bool => $user->canOperateDevices());
    }

    /**
     * Access-control permissions.
     */
    private function defineAccessPermissions(): void
    {
        Gate::define('access.view', fn (User $user): bool => true);

        Gate::define('access.manage', fn (User $user): bool => $user->isFullAdmin());

        Gate::define('access.enroll', fn (User $user): bool => $user->isFullAdmin());

        Gate::define('access.audit', fn (User $user): bool => 
            $user->isFullAdmin() || 
            $user->isAuditOfficer() || 
            $user->isExecutive() || 
            $user->isHrOfficer() || 
            $user->hasPermission('access.audit')
        );

        Gate::define('users.manage', fn (User $user): bool => $user->isFullAdmin());
    }

    /**
     * Personnel management permissions (HR Officer and authorized roles).
     */
    private function definePersonnelPermissions(): void
    {
        Gate::define('personnel.view', fn (User $user): bool => true);

        Gate::define('personnel.create', fn (User $user): bool => $user->isHrOfficer() || $user->hasPermission('personnel.create'));

        Gate::define('personnel.edit', fn (User $user): bool => $user->isHrOfficer() || $user->hasPermission('personnel.edit'));

        Gate::define('personnel.deactivate', fn (User $user): bool => $user->isHrOfficer() || $user->hasPermission('personnel.deactivate'));
    }

    /**
     * Role-based dashboard permissions.
     */
    private function defineDashboardPermissions(): void
    {
        Gate::define('dashboards.all', fn (User $user): bool => $user->isFullAdmin());

        Gate::define('dashboards.executive', fn (User $user): bool => $user->isExecutive());

        Gate::define('dashboards.hr', fn (User $user): bool => $user->isHrOfficer());

        Gate::define('dashboards.audit', fn (User $user): bool => $user->isAuditOfficer());

        Gate::define('reports.executive', fn (User $user): bool => $user->isExecutive() || $user->isAuditOfficer());

        Gate::define('reports.attendance', fn (User $user): bool => $user->isExecutive() || $user->isHrOfficer() || $user->isAuditOfficer());
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
