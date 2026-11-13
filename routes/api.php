<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceConfigController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\EnrollController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TapController;
use Illuminate\Support\Facades\Route;

// ---- Perangkat ESP32 (pakai X-Device-Key) ----
Route::middleware('device.key')->prefix('device')->group(function () {
    Route::get('config', DeviceConfigController::class);
    Route::post('tap', TapController::class);
});

// ---- Aplikasi (Sanctum) ----
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);

    Route::get('attendances', [AttendanceController::class, 'index'])
        ->middleware('role:admin,guardian');

    // ---- Khusus admin ----
    Route::middleware('role:admin')->group(function () {
        Route::get('students', [StudentController::class, 'index']);
        Route::post('students', [StudentController::class, 'store']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::put('students/{student}', [StudentController::class, 'update']);

        Route::post('enroll/start', [EnrollController::class, 'start']);
        Route::get('enroll/status', [EnrollController::class, 'status']);
        Route::post('enroll/cancel', [EnrollController::class, 'cancel']);
    });

    // Sementara: penguji pembatasan role
    Route::get('admin/ping', fn() => response()->json(['success' => true, 'message' => 'pong']))
        ->middleware('role:admin');
});
