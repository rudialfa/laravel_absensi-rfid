<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassAttendanceController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\DeviceConfigController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\EnrollController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TapController;
use App\Http\Controllers\Api\TeacherAttendanceController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\DeviceCardKeyController;
use App\Http\Controllers\Api\SuperController;
use Illuminate\Support\Facades\Route;

// ---- Perangkat ESP32 (pakai X-Device-Key) ----
Route::middleware('device.key')->prefix('device')->group(function () {
    Route::get('config', DeviceConfigController::class);
    Route::get('card-key', DeviceCardKeyController::class);
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

        Route::get('settings', [SettingController::class, 'index']);
        Route::put('settings', [SettingController::class, 'update']);

        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::put('devices/{device}', [DeviceController::class, 'update']);
        Route::post('devices/{device}/rotate-key', [DeviceController::class, 'rotateKey']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy']);

        Route::post('enroll/start', [EnrollController::class, 'start']);
        Route::get('enroll/status', [EnrollController::class, 'status']);
        Route::post('enroll/cancel', [EnrollController::class, 'cancel']);
    });

    // ---- Khusus super admin (pengelola sistem) ----
    Route::middleware('role:super_admin')->prefix('super')->group(function () {
        Route::get('schools', [SuperController::class, 'index']);
        Route::post('schools', [SuperController::class, 'store']);
        Route::put('schools/{school}', [SuperController::class, 'update']);
        Route::get('schools/{school}/admins', [SuperController::class, 'admins']);
        Route::post('schools/{school}/admins', [SuperController::class, 'storeAdmin']);
        Route::post('schools/{school}/card-key/rotate', [SuperController::class, 'rotateCardKey']);
        Route::put('schools/{school}/card-key', [SuperController::class, 'setCardKey']);
        Route::post('admins/{user}/reset-password', [SuperController::class, 'resetAdminPassword']);
    });

    // Sementara: penguji pembatasan role
    Route::get('admin/ping', fn() => response()->json(['success' => true, 'message' => 'pong']))
        ->middleware('role:admin');
});
