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

        // All named rate limiters read their thresholds from config/rate_limits.php
        // so limits live in one declarative place rather than as literals here.

        // Sync API general throttles.
        RateLimiter::for('sync-per-user', function (Request $request) {
            return $this->buildLimits('sync_per_user', $request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('sync-global', function (Request $request) {
            return $this->buildLimits('sync_global', null);
        });

        RateLimiter::for('sync-batch', function (Request $request) {
            return $this->buildLimits('sync_batch', $request->user()?->id ?: $request->ip());
        });

        // Connection-token redemption. Keyed per authenticated user.
        RateLimiter::for('connection-attempts', function (Request $request) {
            return $this->buildLimits('connection_attempts', $request->user()?->id ?: $request->ip());
        });

        // Registration. Keyed per IP.
        RateLimiter::for('register', function (Request $request) {
            return $this->buildLimits('register', $request->ip());
        });

        // Login. Keyed per email + IP.
        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();

            return $this->buildLimits('login', $key);
        });

        // Unauthenticated email-check endpoint. Keyed per IP.
        RateLimiter::for('email-check', function (Request $request) {
            return $this->buildLimits('email_check', $request->ip());
        });

        // Register observers
        PRComment::observe(PRCommentObserver::class);
        PRHighFive::observe(PRHighFiveObserver::class);

        // Register event listeners
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\LiftLogCompleted::class,
            \App\Listeners\DetectAndRecordPRs::class
        );

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

    /**
     * Build the Limit objects for a named limiter from config/rate_limits.php.
     *
     * Reads `per_minute` (required) and `per_hour` (optional) for the given
     * config key and applies the same throttle key to each window. A null or
     * missing window is skipped.
     *
     * @return array<int, \Illuminate\Cache\RateLimiting\Limit>
     */
    private function buildLimits(string $key, int|string|null $by): array
    {
        $config = config("rate_limits.{$key}", []);
        $limits = [];

        if (! empty($config['per_minute'])) {
            $limit = Limit::perMinute($config['per_minute']);
            $limits[] = $by === null ? $limit : $limit->by($by);
        }

        if (! empty($config['per_hour'])) {
            $limit = Limit::perHour($config['per_hour']);
            $limits[] = $by === null ? $limit : $limit->by($by);
        }

        return $limits;
    }
}
