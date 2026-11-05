<?php

use App\Http\Controllers\Api\AuthApi;
use App\Http\Controllers\Api\CronApi;
use App\Http\Controllers\Api\DoorApi;
use App\Http\Controllers\Api\GateApi;
use App\Http\Controllers\Api\ReferenceApi;
use App\Http\Controllers\FileController;
use App\Http\Middleware\ApiToken;
use Illuminate\Support\Facades\Route;

/*
 * The JSON API (/api/...): what the desktop door app and the gate call, with the same URLs and answers as the
 * PHP API. The web pages do not use it. Sign in with POST /api/auth/login and send "Authorization: <token>".
 */

// Open: sign-in, the cron address (its own key), public rules, photos.
Route::post('auth/login', [AuthApi::class, 'login']);
Route::post('auth/refresh', [AuthApi::class, 'refresh']);
Route::post('auth/logout', [AuthApi::class, 'logout']);
Route::match(['get', 'post'], 'cron-run', [CronApi::class, 'run']);
Route::match(['get', 'post'], 'cron', [CronApi::class, 'run']);
Route::get('booking-rules', [ReferenceApi::class, 'bookingRules']);
Route::get('menu', [ReferenceApi::class, 'menu']);
Route::get('files/{table}/{id}/{name}', [FileController::class, 'show']);

// Signed in.
Route::middleware(ApiToken::class)->group(function () {
    Route::get('users', [DoorApi::class, 'users']);
    Route::get('users/search', [DoorApi::class, 'search']);
    Route::get('users/by-badge', [DoorApi::class, 'byBadge']);
    Route::get('users/{id}', [DoorApi::class, 'user']);
    Route::match(['patch', 'put'], 'users/{id}', [DoorApi::class, 'updateUser']);

    Route::get('orders', [DoorApi::class, 'orders']);
    Route::get('orders/first-missed', [DoorApi::class, 'firstMissed']);
    Route::get('orders/{id}', [DoorApi::class, 'order']);
    Route::match(['patch', 'put'], 'orders/{id}', [DoorApi::class, 'updateOrder']);

    Route::post('mealboard/door-heartbeat', [DoorApi::class, 'heartbeat']);

    Route::get('meal-groups', [ReferenceApi::class, 'mealGroups']);
    Route::post('meal-groups', [ReferenceApi::class, 'saveGroup']);
    Route::match(['patch', 'put'], 'meal-groups/{id}', [ReferenceApi::class, 'saveGroup']);
    Route::delete('meal-groups/{id}', [ReferenceApi::class, 'deleteGroup']);
    Route::get('department-rules', [ReferenceApi::class, 'departmentRules']);
    Route::put('department-rules/{department}', [ReferenceApi::class, 'saveDepartment']);

    Route::post('gate', [GateApi::class, 'handle']);
});
