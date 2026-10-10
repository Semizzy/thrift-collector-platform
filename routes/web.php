<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'foundation',
]));

Route::view('/reset-password', 'auth.reset-password');
