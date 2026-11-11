<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TapController extends Controller
{
    public function __invoke(Request $request)
    {
        $v = Validator::make($request->all(), [
            'uid' => ['required', 'string', 'regex:/^[0-9A-Fa-f]{8,20}$/'],
        ]);

        if ($v->fails()) {
            return $this->fail('INVALID_UID', 'UID tidak valid', 422);
        }

        $uid = strtoupper($request->input('uid'));

        // Hanya kartu terdaftar DAN siswa aktif
        $student = Student::where('rfid_uid', $uid)
            ->where('is_active', true)
            ->first();

        if (!$student) {
            return $this->fail('CARD_NOT_FOUND', 'Kartu tidak terdaftar', 404);
        }

        $now           = now(); // waktu resmi dari server
        $today         = $now->toDateString();
        $hm            = $now->format('H:i');
        $lateTime      = Setting::get('late_time', '07:15');
        $checkoutStart = Setting::get('checkout_start', '12:00');
        $isCheckoutTime = $hm >= $checkoutStart;

        $attendance = Attendance::where('student_id', $student->id)
            ->where('date', $today)
            ->first();

        // ---- Belum ada absen masuk hari ini ----
        if (!$attendance) {
            if ($isCheckoutTime) {
                return response()->json([
                    'success' => false,
                    'code'    => 'BELUM_MASUK',
                    'student' => $student->name,
                    'message' => 'Belum absen masuk',
                ]);
            }

            return $this->checkIn($request, $student, $now, $hm > $lateTime ? 'terlambat' : 'hadir');
        }

        // ---- Sudah pulang ----
        if ($attendance->checked_out_at) {
            return response()->json([
                'success' => true,
                'code'    => 'ALREADY_OUT',
                'student' => $student->name,
                'message' => 'Sudah absen pulang',
            ]);
        }

        // ---- Sudah masuk, belum waktunya pulang ----
        if (!$isCheckoutTime) {
            return response()->json([
                'success' => true,
                'code'    => 'ALREADY',
                'student' => $student->name,
                'message' => 'Sudah absen',
            ]);
        }

        // ---- Pulang ----
        // Update bersyarat: kalau dua tap bersamaan, hanya satu yang berhasil
        $updated = Attendance::where('id', $attendance->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => $now]);

        if ($updated === 0) {
            return response()->json([
                'success' => true,
                'code'    => 'ALREADY_OUT',
                'student' => $student->name,
                'message' => 'Sudah absen pulang',
            ]);
        }

        // TODO (tahap notifikasi): dispatch job FCM "sudah pulang" ke wali dan admin.

        return response()->json([
            'success' => true,
            'code'    => 'CHECKOUT_OK',
            'student' => $student->name,
            'time'    => $now->format('H:i'),
        ]);
    }

    private function checkIn(Request $request, Student $student, $now, string $status)
    {
        try {
            $attendance = Attendance::create([
                'student_id'    => $student->id,
                'date'          => $now->toDateString(),
                'checked_in_at' => $now,
                'status'        => $status,
                'device_id'     => $request->attributes->get('device')->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Dua tap bersamaan: salah satu kalah di UNIQUE constraint
            if ($e->getCode() === '23000') {
                return response()->json([
                    'success' => true,
                    'code'    => 'ALREADY',
                    'student' => $student->name,
                    'message' => 'Sudah absen',
                ]);
            }
            throw $e;
        }

        // TODO (tahap notifikasi): dispatch job FCM "sudah masuk" ke wali dan admin.

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'student' => $student->name,
            'status'  => $attendance->status,
            'time'    => $attendance->checked_in_at->format('H:i'),
        ]);
    }

    private function fail(string $code, string $message, int $http)
    {
        return response()->json([
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ], $http);
    }
}