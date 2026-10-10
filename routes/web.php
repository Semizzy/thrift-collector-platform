<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'foundation',
]));

Route::view('/reset-password', 'auth.reset-password');
Route::view('/accept-business-invitation', 'auth.accept-business-invitation');
