<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Models\MeasurementType;
use Illuminate\Support\Facades\Auth;
use App\Http\View\Composers\ExerciseAliasComposer;
use Illuminate\Support\Facades\Log;
use App\Models\PRComment;
use App\Models\PRHighFive;
use App\Observers\PRCommentObserver;
use App\Observers\PRHighFiveObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Store logs captured during the request
     */
    protected static $capturedLogs = [];

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
        // Override password reset link to point to the Athlete PWA
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $athleteUrl = config('services.athlete.url', 'https://squirby.app');
            return $athleteUrl . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
        });

        // Register rate limiters for the Sync API
        RateLimiter::for('sync-per-user', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('sync-global', function (Request $request) {
            return Limit::perMinute(60);
        });

        RateLimiter::for('sync-batch', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Throttle connection-token redemption to make the 6-digit code
        // infeasible to brute-force (10^6 space, 1-hour validity).
        RateLimiter::for('connection-attempts', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Registration: a tight burst limit plus a looser hourly cap to slow
        // paced mass account creation. Keyed per IP.
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perMinute(2)->by($request->ip()),
                Limit::perHour(5)->by($request->ip()),
            ];
        });

        // Login: burst limit plus an hourly cap to slow sustained brute force.
        // Keyed per email + IP so one struggling user (or shared NAT IP) is not
        // locked out by unrelated traffic, and an attacker spreading across many
        // emails is still caught per email.
        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();

            return [
                Limit::perMinute(2)->by($key),
                Limit::perHour(20)->by($key),
            ];
        });

        // Throttle the unauthenticated email-check endpoint (per IP) to prevent
        // bulk account enumeration / scraping. The hourly cap targets slow,
        // patient scraping that stays under the per-minute limit.
        RateLimiter::for('email-check', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(30)->by($request->ip()),
            ];
        });

        // Register observers
        PRComment::observe(PRCommentObserver::class);
        PRHighFive::observe(PRHighFiveObserver::class);

        // Enable query log for non-production environments
        // 2025-11-09 Temporarily enabled across all environments to troubleshoot discrepancy
        // in recommendations between local and product for 1 user.
        //if (config('app.env') !== 'production') {
            \Illuminate\Support\Facades\DB::enableQueryLog();
        //}

        // Capture logs for admin users and when impersonating
        Log::listen(function ($log) {
            if (Auth::check() && (Auth::user()->hasRole('Admin') || session()->has('impersonator_id'))) {
                self::$capturedLogs[] = [
                    'level' => $log->level,
                    'message' => $log->message,
                    'context' => $log->context,
                ];
            }
        });

        // Register view composer for exercise alias display
        View::composer([
            'exercises.index',
            'lift-logs.*',
            'programs.*',
            'mobile-entry.*',
            'components.top-exercises-buttons',
            'components.lift-log-form',
            'components.exercise-form',
        ], ExerciseAliasComposer::class);

        View::composer('app', function ($view) {
            if (Auth::check()) {
                // Provide menu service to the view
                $menuService = app(\App\Services\MenuService::class);
                $view->with('menuService', $menuService);
            }
            
            // Show database info and git log for admin users or when impersonating
            $isAdmin = Auth::check() && Auth::user()->hasRole('Admin');
            if ($isAdmin || session()->has('impersonator_id')) {
                // Git log
                try {
                    $gitBranch = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
                    $gitLog = shell_exec('git log -n 25 --pretty=format:"%h - %s (%cr)"');
                    $view->with('gitBranch', $gitBranch);
                    $view->with('gitLog', $gitLog);
                } catch (\Exception $e) {
                    $view->with('gitBranch', 'unknown');
                    $view->with('gitLog', 'Could not retrieve git log.');
                }
                
                // Query log
                $queries = \Illuminate\Support\Facades\DB::getQueryLog();
                $queryCount = count($queries);
                $dbConnection = config('database.default');
                $dbDriver = config("database.connections.{$dbConnection}.driver");
                $dbName = config("database.connections.{$dbConnection}.database");
                $dbUsername = config("database.connections.{$dbConnection}.username");
                
                $view->with('queryCount', $queryCount);
                $view->with('queries', $queries);
                $view->with('dbConnection', $dbConnection);
                $view->with('dbDriver', $dbDriver);
                $view->with('dbName', $dbName);
                $view->with('dbUsername', $dbUsername);
                $view->with('showDebugInfo', true);
                
                // Pass captured logs to view
                $view->with('logs', self::$capturedLogs);
            }
        });
    }
}
