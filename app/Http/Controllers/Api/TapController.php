<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendAttendanceNotification;
use App\Jobs\SendTeacherAttendanceNotification;
use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAttendance;
use App\Services\CardOwner;
use App\Services\CardToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

        // ---- Mode pendaftaran: tangkap UID, bukan absen ----
        // pull() mengambil sekaligus menghapus, jadi hanya satu kartu yang tertangkap.
        if (Cache::pull(EnrollController::KEY_ACTIVE)) {
            $owner      = CardOwner::find($uid);
            $ownerModel = $owner['model'] ?? null;

            Cache::put(EnrollController::KEY_CAPTURED, [
                'uid'        => $uid,
                'registered' => $owner !== null,
                'owner_type' => $owner['type'] ?? null,
                'student'    => $ownerModel?->name,
                'is_active'  => $ownerModel ? (bool) $ownerModel->is_active : null,
                'at'         => now()->toIso8601String(),
            ], EnrollController::CAPTURED_SECONDS);

            CardToken::discard();

            $response = [
                'success' => true,
                'code'    => 'ENROLL_CAPTURED',
                'message' => 'Kartu dibaca (mode daftar)',
            ];

            // Token hanya untuk kartu baru. Kartu milik murid atau guru lain tidak disentuh.
            if ($owner === null) {
                $response['write_token'] = CardToken::issue($uid);
            }

            return response()->json($response);
        }

        // ---- Cari pemilik kartu: murid aktif, kalau tidak ada guru aktif ----
        $student = Student::where('rfid_uid', $uid)->where('is_active', true)->first();
        $teacher = $student
            ? null
            : Teacher::where('rfid_uid', $uid)->where('is_active', true)->first();

        $person = $student ?? $teacher;

        if (!$person) {
            return $this->fail('CARD_NOT_FOUND', 'Kartu tidak terdaftar', 404);
        }

        // ---- Token kartu (anti clone), berlaku untuk murid dan guru ----
        $token = $request->input('token');
        $token = (is_string($token) && preg_match('/^[0-9A-Fa-f]{32}$/', $token))
            ? strtolower($token)
            : null;

        if ($person->card_token_hash !== null) {
            // Kartu ini sudah bertoken: token wajib cocok, apa pun setelannya
            if (!CardToken::matches($person->card_token_hash, $token)) {
                Log::warning('Tap ditolak: token kartu tidak sah', ['uid' => $uid]);

                return $this->fail('TOKEN_INVALID', 'Kartu tidak sah', 403);
            }
        } elseif (Setting::get('require_card_token', '0') === '1') {
            return $this->fail('TOKEN_REQUIRED', 'Kartu perlu didaftarkan ulang', 403);
        }

        $now            = now(); // waktu resmi dari server
        $today          = $now->toDateString();
        $hm             = $now->format('H:i');
        $checkoutStart  = Setting::get('checkout_start', '12:00');
        $isCheckoutTime = $hm >= $checkoutStart;

        if ($teacher) {
            return $this->handleTeacher($request, $teacher, $now, $isCheckoutTime);
        }

        // =============== Alur murid ===============
        $lateTime = Setting::get('late_time', '07:15');

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

        // Notifikasi pulang ke wali dan admin (masuk antrean, diproses queue:work)
        SendAttendanceNotification::dispatch($attendance->id, 'pulang');

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

        // Notifikasi masuk ke wali dan admin (masuk antrean, diproses queue:work)
        SendAttendanceNotification::dispatch($attendance->id, 'masuk');

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'student' => $student->name,
            'status'  => $attendance->status,
            'time'    => $attendance->checked_in_at->format('H:i'),
        ]);
    }

    // =============== Alur guru (tanpa status terlambat) ===============
    private function handleTeacher(Request $request, Teacher $teacher, $now, bool $isCheckoutTime)
    {
        $attendance = TeacherAttendance::where('teacher_id', $teacher->id)
            ->where('date', $now->toDateString())
            ->first();

        // ---- Belum ada absen masuk hari ini ----
        if (!$attendance) {
            if ($isCheckoutTime) {
                return response()->json([
                    'success' => false,
                    'code'    => 'BELUM_MASUK',
                    'role'    => 'teacher',
                    'student' => $teacher->name,
                    'message' => 'Belum absen masuk',
                ]);
            }

            return $this->teacherCheckIn($request, $teacher, $now);
        }

        // ---- Sudah pulang ----
        if ($attendance->checked_out_at) {
            return response()->json([
                'success' => true,
                'code'    => 'ALREADY_OUT',
                'role'    => 'teacher',
                'student' => $teacher->name,
                'message' => 'Sudah absen pulang',
            ]);
        }

        // ---- Sudah masuk, belum waktunya pulang ----
        if (!$isCheckoutTime) {
            return response()->json([
                'success' => true,
                'code'    => 'ALREADY',
                'role'    => 'teacher',
                'student' => $teacher->name,
                'message' => 'Sudah absen',
            ]);
        }

        // ---- Pulang (update bersyarat, tahan tap bersamaan) ----
        $updated = TeacherAttendance::where('id', $attendance->id)
            ->whereNull('checked_out_at')
            ->update(['checked_out_at' => $now]);

        if ($updated === 0) {
            return response()->json([
                'success' => true,
                'code'    => 'ALREADY_OUT',
                'role'    => 'teacher',
                'student' => $teacher->name,
                'message' => 'Sudah absen pulang',
            ]);
        }

        // Notifikasi pulang ke admin (masuk antrean, diproses queue:work)
        SendTeacherAttendanceNotification::dispatch($attendance->id, 'pulang');

        return response()->json([
            'success' => true,
            'code'    => 'CHECKOUT_OK',
            'role'    => 'teacher',
            'student' => $teacher->name,
            'time'    => $now->format('H:i'),
        ]);
    }

    private function teacherCheckIn(Request $request, Teacher $teacher, $now)
    {
        try {
            $attendance = TeacherAttendance::create([
                'teacher_id'    => $teacher->id,
                'date'          => $now->toDateString(),
                'checked_in_at' => $now,
                'device_id'     => $request->attributes->get('device')->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Dua tap bersamaan: salah satu kalah di UNIQUE constraint
            if ($e->getCode() === '23000') {
                return response()->json([
                    'success' => true,
                    'code'    => 'ALREADY',
                    'role'    => 'teacher',
                    'student' => $teacher->name,
                    'message' => 'Sudah absen',
                ]);
            }
            throw $e;
        }

        // Notifikasi masuk ke admin (masuk antrean, diproses queue:work)
        SendTeacherAttendanceNotification::dispatch($attendance->id, 'masuk');

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'role'    => 'teacher',
            'student' => $teacher->name,
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
