<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

Route::get('login', function () {
    return response()->json(['message' => 'Authentication required'], 401);
})->name('login');