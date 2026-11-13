<?php

use Illuminate\Support\Facades\Route;

// The backend is API-only; the React app is served separately.
Route::get('/', fn () => response()->json(['name' => 'Wingora API', 'version' => 'v1']));
