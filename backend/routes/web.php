<?php

use App\Http\Controllers\Auth\SsoController;
use Illuminate\Support\Facades\Route;

// The backend is API-only; the React app is served separately.
Route::get('/', fn () => response()->json(['name' => 'Wingora API', 'version' => 'v1']));

// OIDC single sign-on: browser redirects to and from the identity provider (web session, not Sanctum SPA).
Route::prefix('api/v1/auth/sso')->middleware('throttle:sso')->name('sso.')->group(function () {
    Route::get('callback', [SsoController::class, 'callback'])->name('callback');
    Route::get('{slug}/redirect', [SsoController::class, 'redirect'])->where('slug', '[a-z0-9-]{1,63}')->name('redirect');
});
