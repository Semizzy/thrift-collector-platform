<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name', 'SEMIZZY WEBMASTER Thrift Collector Platform'),
    'status' => 'foundation',
    'message' => 'The platform foundation is being implemented. No financial operations are enabled.',
]));
