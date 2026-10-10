<?php

use Illuminate\Support\Facades\Route;

$serveDashboardApp = static function () {
    $entry = public_path('app/index.html');

    if (! is_file($entry)) {
        return response()->json([
            'status' => 'frontend_build_missing',
            'message' => 'Build the web app with npm run build before serving dashboard pages.',
        ], 503);
    }

    return response()->file($entry, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'X-Content-Type-Options' => 'nosniff',
    ]);
};

Route::get('/', function () use ($serveDashboardApp) {
    if (is_file(public_path('app/index.html'))) {
        return $serveDashboardApp();
    }

    return response()->json([
        'name' => config('app.name'),
        'status' => 'foundation',
    ]);
});

Route::get('/dashboard', $serveDashboardApp);
Route::get('/admin', $serveDashboardApp);

Route::view('/reset-password', 'auth.reset-password');
Route::view('/accept-business-invitation', 'auth.accept-business-invitation');
