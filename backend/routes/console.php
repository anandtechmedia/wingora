<?php

use App\Audit\AuditChain;
use App\Audit\Retention;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Security\RoleCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
Schedule::command('wingora:audit:prune')->dailyAt('02:30')->withoutOverlapping();

Artisan::command('wingora:audit:prune {--dry-run}', function () {
    $results = Retention::prune((bool) $this->option('dry-run'));
    $this->table(['Chain', 'Entries', 'Through sequence'], array_map(fn ($r) => [$r['chain'], $r['deleted'], $r['through_sequence']], $results));
    $this->info($results ? ($this->option('dry-run') ? 'Dry run: nothing deleted.' : 'Expired audit entries removed and recorded.') : 'Nothing to prune.');
})->purpose('Apply audit retention policies (the only supported deletion path for audit entries)');

Artisan::command('wingora:audit:verify {--tenant= : Tenant public id; omit for all chains}', function () {
    $query = DB::table('audit_chains')->orderBy('chain');
    if ($publicId = $this->option('tenant')) {
        $query->where('tenant_id', Tenant::query()->where('public_id', $publicId)->value('id') ?? 0);
    }
    $results = $query->pluck('chain')->map(fn ($chain) => AuditChain::verify($chain));
    $this->table(['Chain', 'Entries', 'Valid', 'First error'], $results->map(fn ($r) => [$r['chain'], $r['entries'], $r['valid'] ? 'yes' : 'NO', $r['errors'][0] ?? ''])->all());

    return $results->every(fn ($r) => $r['valid']) ? 0 : 1;
})->purpose('Verify the audit hash chains');

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
