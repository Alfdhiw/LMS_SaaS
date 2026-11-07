<?php

use Illuminate\Support\Facades\Route;

// M0: routes only, no tenant identity, data, or access control yet.
Route::get('/', static fn () => view('welcome'))->name('home');
Route::get('/health', static fn () => response()->json(['status' => 'ok']))->name('health');
