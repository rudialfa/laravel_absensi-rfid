<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'code'    => 'VALIDATION',
                'message' => 'Email dan password wajib diisi',
            ], 422);
        }

        // Belum ada sekolah aktif saat login, jadi cari tanpa filter sekolah
        $user = User::withoutGlobalScopes()
            ->where('email', strtolower(trim($request->input('email'))))
            ->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'code'    => 'INVALID_CREDENTIALS',
                'message' => 'Email atau password salah',
            ], 401);
        }

        // Selain super_admin, akun harus terhubung ke sekolah yang aktif
        $school = $user->school_id ? School::find($user->school_id) : null;

        if ($user->role !== 'super_admin') {
            if (!$school) {
                return $this->fail('NO_SCHOOL', 'Akun belum terhubung ke sekolah', 403);
            }

            if (!$school->is_active) {
                return $this->fail('SCHOOL_DISABLED', 'Sekolah dinonaktifkan. Hubungi pengelola.', 403);
            }
        }

        // Guru yang dinonaktifkan tidak boleh masuk
        if ($user->role === 'teacher') {
            $teacher = Teacher::withoutGlobalScopes()->where('user_id', $user->id)->first();
            if ($teacher && !$teacher->is_active) {
                return $this->fail('ACCOUNT_DISABLED', 'Akun dinonaktifkan. Hubungi admin.', 403);
            }
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $this->userData($user, $school),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user'    => $this->userData($user, $user->school_id ? School::find($user->school_id) : null),
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        // Hapus token FCM HP ini supaya tidak menerima notifikasi setelah logout
        if ($request->filled('fcm_token')) {
            DeviceToken::where('user_id', $user->id)
                ->where('fcm_token', $request->input('fcm_token'))
                ->delete();
        }

        $user->currentAccessToken()->delete();

        return response()->json(['success' => true]);
    }

    private function userData(User $user, ?School $school): array
    {
        return [
            'id'        => $user->id,
            'name'      => $user->name,
            'email'     => $user->email,
            'role'      => $user->role,
            'school_id' => $school?->id,
            'school'    => $school?->name,
        ];
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
