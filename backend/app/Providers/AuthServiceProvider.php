<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Policies\MemberPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        Project::class => ProjectPolicy::class,
        Task::class => TaskPolicy::class,
        Tenant::class => MemberPolicy::class,
    ];

    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Auth::extend('jwt', function ($app, string $name, array $config) {
            $guard = new JwtGuard(
                Auth::createUserProvider($config['provider']),
                $app['request'],
                $app->make(TokenService::class),
            );

            // Keep the guard's request fresh (and its user cache reset) whenever
            // the request is rebound — e.g. between requests in a test run.
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }
}
