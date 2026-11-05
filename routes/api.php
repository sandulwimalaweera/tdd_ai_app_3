<?php

use App\Http\Controllers\SignInController;
use App\Http\Controllers\TaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/sign-in', SignInController::class)->middleware('throttle:sign-in')->name('auth.sign-in');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('tasks', TaskController::class)->whereUuid('task');
