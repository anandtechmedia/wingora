<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Security\Permission;
use App\Security\TenantUserProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Auth::provider('wingora', fn ($app, array $config) => new TenantUserProvider($app['hash'], $config['model']));

        // Mass assignment of non-fillable attributes throws instead of being silently dropped.
        Model::preventSilentlyDiscardingAttributes();
        Model::preventAccessingMissingAttributes();

        if ($this->app->isProduction()) {
            DB::prohibitDestructiveCommands();
        }

        Password::defaults(fn () => Password::min(12)->max(128)->letters()->mixedCase()->numbers()->symbols()
            ->when($this->app->isProduction(), fn (Password $rule) => $rule->uncompromised()));

        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim((string) config('security.frontend_url'), '/')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->email));

        // Gates: one per permission. There is deliberately no Gate::before "super user" bypass.
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $login = config('security.login');

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute($login['per_minute'])->by('login:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute($login['per_ip_per_minute'])->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(5)->by('mfa:'.(($request->hasSession() ? $request->session()->get('auth.pending_user_id') : null) ?? $request->user()?->id ?? $request->ip())));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by('pwreset:'.strtolower((string) $request->input('email'))),
            Limit::perMinute(10)->by('pwreset-ip:'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}
