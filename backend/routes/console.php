<?php

use App\Models\Role;
use App\Models\User;
use App\Security\RoleCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

Artisan::command('wingora:platform-user {email} {name} {--role=platform-admin}', function (string $email, string $name) {
    $role = $this->option('role');
    if (! in_array($role, [RoleCatalog::PLATFORM_OWNER, RoleCatalog::PLATFORM_ADMIN], true)) {
        $this->error('Role must be platform-owner or platform-admin.');

        return 1;
    }

    RoleCatalog::syncPlatformRoles();
    $user = User::create(['name' => $name, 'email' => Str::lower($email), 'password' => Str::password(40)]);
    $user->roles()->attach(Role::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('key', $role)->value('id'), ['created_at' => now()]);
    Password::sendResetLink(['email' => $user->email]);

    $this->info("Created {$role} {$user->email}; a password-reset link was sent. MFA enrolment is required at first sign-in.");
})->purpose('Create a platform staff account (no password is ever printed)');
