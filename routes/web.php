<?php

use Illuminate\Support\Facades\Route;

// M0 local-only routes. Central and tenant routes are registered separately.
Route::domain('localhost')->group(function (): void {
    Route::get('/', static fn () => view('welcome'))->name('home');
    Route::get('/health', static fn () => response()->json(['status' => 'ok']))->name('health');
});
