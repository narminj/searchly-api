<?php

namespace App\Providers;

use App\Models\Product;
use App\Observers\ProductObserver;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Product::observe(ProductObserver::class);

        $this->configureRateLimiting();

        // Already-authenticated users hitting a guest route land on the manual
        RedirectIfAuthenticated::redirectUsing(fn () => '/manual');
    }

    /**
     * Per-IP limits for the public, unauthenticated search API.
     * Search is the most expensive endpoint (fuzzy matching + aggregations
     * on every request), so it gets the tightest limit.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('suggest', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Brute-force protection on login — keyed by email + IP
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')) . '|' . $request->ip()));
    }
}
