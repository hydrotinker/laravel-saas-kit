<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

// Public auth endpoints.
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);
Route::post('refresh', [AuthController::class, 'refresh']);

// Authenticated + tenant-scoped endpoints.
Route::middleware(['auth:api', 'tenant'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('projects.tasks', TaskController::class);

    Route::get('members', [MemberController::class, 'index']);
    Route::post('members', [MemberController::class, 'store']);
    Route::patch('members/{member}', [MemberController::class, 'updateRole']);
    Route::delete('members/{member}', [MemberController::class, 'destroy']);
});
