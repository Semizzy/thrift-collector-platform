<?php

use App\Domain\Country\CountryConfiguration;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\RequireBusinessMembership;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'service' => 'thrift-collector-platform-api',
        'api_version' => 'v1',
    ]));

    Route::get('/countries', fn () => response()->json([
        'data' => array_map(
            static fn (CountryConfiguration $country): array => $country->toArray(),
            CountryConfiguration::supported(),
        ),
    ]));

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

        Route::middleware(AuthenticateApiToken::class)->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware(AuthenticateApiToken::class)->group(function (): void {
        Route::get('/businesses', [BusinessController::class, 'index']);
        Route::post('/businesses', [BusinessController::class, 'store'])->middleware('throttle:10,1');

        Route::middleware(RequireBusinessMembership::class)->prefix('business')->group(function (): void {
            Route::get('/current', [BusinessController::class, 'current']);
        });
    });
});
