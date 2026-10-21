<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => config('app.name'),
    'version' => config('registry.version'),
    'docs' => 'https://docs.fleetbase.io/registry',
    'catalog' => url('/v1/catalog/extensions'),
]));
