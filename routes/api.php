<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassAttendanceController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\DeviceConfigController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\EnrollController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TapController;
use App\Http\Controllers\Api\TeacherAttendanceController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;

// ---- Perangkat ESP32 (pakai X-Device-Key) ----
Route::middleware('device.key')->prefix('device')->group(function () {
    Route::get('config', DeviceConfigController::class);
    Route::post('tap', TapController::class);
    Route::post('enroll-confirm', [EnrollController::class, 'confirm']);
});

// ---- Aplikasi (Sanctum) ----
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);

    Route::get('attendances', [AttendanceController::class, 'index'])
        ->middleware('role:admin,guardian');

    // ---- Guru ----
    Route::get('my-classes', [ClassAttendanceController::class, 'myClasses'])
        ->middleware('role:teacher');

    // ---- Guru (hanya kelas yang diampu) dan admin (semua kelas) ----
    Route::get('classes/{classRoom}/attendances', [ClassAttendanceController::class, 'show'])
        ->middleware('role:admin,teacher');

    // ---- Khusus admin ----
    Route::middleware('role:admin')->group(function () {
        Route::get('students', [StudentController::class, 'index']);
        Route::post('students', [StudentController::class, 'store']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::put('students/{student}', [StudentController::class, 'update']);
        Route::post('students/{student}/replace-card', [StudentController::class, 'replaceCard']);

        Route::get('classes', [ClassController::class, 'index']);
        Route::post('classes', [ClassController::class, 'store']);
        Route::get('classes/{classRoom}', [ClassController::class, 'show']);
        Route::put('classes/{classRoom}', [ClassController::class, 'update']);
        Route::delete('classes/{classRoom}', [ClassController::class, 'destroy']);

        Route::get('teachers', [TeacherController::class, 'index']);
        Route::post('teachers', [TeacherController::class, 'store']);
        Route::get('teachers/{teacher}', [TeacherController::class, 'show']);
        Route::put('teachers/{teacher}', [TeacherController::class, 'update']);
        Route::post('teachers/{teacher}/replace-card', [TeacherController::class, 'replaceCard']);

        Route::get('teacher-attendances', [TeacherAttendanceController::class, 'index']);

        Route::get('guardians', [GuardianController::class, 'index']);
        Route::post('guardians', [GuardianController::class, 'store']);
        Route::put('guardians/{guardian}', [GuardianController::class, 'update']);
        Route::delete('guardians/{guardian}', [GuardianController::class, 'destroy']);

        Route::post('users/{user}/reset-password', [GuardianController::class, 'resetPassword']);

        Route::post('enroll/start', [EnrollController::class, 'start']);
        Route::get('enroll/status', [EnrollController::class, 'status']);
        Route::post('enroll/cancel', [EnrollController::class, 'cancel']);
    });

    // Sementara: penguji pembatasan role
    Route::get('admin/ping', fn() => response()->json(['success' => true, 'message' => 'pong']))
        ->middleware('role:admin');
});
