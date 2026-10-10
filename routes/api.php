<?php

use App\Domain\Country\CountryConfiguration;
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
});
