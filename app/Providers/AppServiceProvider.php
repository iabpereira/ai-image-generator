<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn (): PixelforgeClient => new PixelforgeClient(
            (string) config('services.pixelforge.base_url'),
            (string) config('services.pixelforge.api_key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        Gate::define('viewAdminReport', fn (User $user): bool => $user->is_admin);
        Gate::define('manageUsers', fn (User $user): bool => $user->is_admin);
        Gate::define('managePricing', fn (User $user): bool => $user->is_admin);
        Gate::define('impersonate', fn (User $admin, User $user): bool => $admin->is_admin
            && ! $user->is_admin
            && ! $admin->is($user));
    }

    /**
     * Pixelforge allows 60 requests per minute per account, shared by every key.
     * Submissions take at most 50, leaving headroom for polling and the local key.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('pixelforge', fn (): Limit => Limit::perMinute(50));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
